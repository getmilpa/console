<?php

/**
 * This file is part of Milpa Console — the projection layer that turns one declared Operation into the shape each surface speaks.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/console
 */

declare(strict_types=1);

namespace Milpa\Console\Tests\Http;

use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Console\Events\ConsoleEvents;
use Milpa\Console\Http\HttpProjector;
use Milpa\Console\OperationRunner;
use Milpa\Console\OperationStoppedException;
use Milpa\Http\Routing\RouteResult;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Over HTTP, what an operation ANSWERED decides the status — not how it was declared.
 *
 * The house has one rule for an operation's verdict ({@see OperationRunner::verdict()}): a boolean `ok` at the root of
 * its result IS the verdict. The terminal exits 1 on a negative one, and the TUI paints it. The HTTP projector picked
 * 201 or 200 from `$op->mutating` alone, so an operation that ran and answered `ok: false` — a graph node its caller
 * may not run, an answer that was not recorded, a write the house refused — reached a client that looks at the status
 * as a success (greenhouse evidence/1122, decisions/0583).
 *
 * A negative verdict answers 409, with the operation's own body untouched: the status says the house said no, the
 * body says why. 422 stays what it was — the input did not fit the schema — so a caller can tell «fix your request»
 * from «the house answered no».
 */
#[CoversClass(HttpProjector::class)]
final class TheVerdictReachesTheStatusTest extends TestCase
{
    public function testAMutatingOperationThatAnswersNoIsNotACreated(): void
    {
        $refusal = ['ok' => false, 'error' => "The node 'publish' (essay:publish) did not run: it needs the scope essay:publish.", 'state' => 'publish', 'refused' => ['node' => 'publish']];

        $response = $this->call($this->operation('graph:start', true, static fn (): array => $refusal));

        self::assertSame(409, $response->getStatusCode(), 'an operation that ran and said no is not a 201');
        self::assertSame($refusal, json_decode((string) $response->getBody(), true), 'and what it said arrives whole: the status does not replace the sentence');
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function testAReadThatAnswersNoIsNotAnOk(): void
    {
        $report = ['ok' => false, 'problems' => ['the manifest names a class that does not exist']];

        $response = $this->call($this->operation('house:check', false, static fn (): array => $report), 'GET');

        self::assertSame(409, $response->getStatusCode(), 'a diagnostic that reports ok: false is not a 200 either — the terminal already exits 1 on it');
        self::assertSame($report, json_decode((string) $response->getBody(), true));
    }

    /**
     * @return iterable<string, array{0: mixed, 1: bool}>
     */
    public static function answersThatAreNotANo(): iterable
    {
        yield 'a result that says ok: true' => [['ok' => true, 'id' => 7], true];
        yield 'a result that carries no verdict' => [['id' => 7], true];
        yield 'an ok that is not a boolean is not a verdict' => [['ok' => 'no'], true];
        yield 'an ok of zero is not a verdict' => [['ok' => 0], false];
        yield 'an ok of null is not a verdict' => [['ok' => null], true];
        yield 'an ok: false that is not at the root' => [['result' => ['ok' => false], 'steps' => [['ok' => false]]], false];
        yield 'a list' => [[['ok' => false]], true];
        yield 'an empty result' => [[], false];
    }

    /** What is not a negative verdict keeps the status the declaration gives it — exactly as before. */
    #[DataProvider('answersThatAreNotANo')]
    public function testEveryOtherAnswerKeepsTheStatusOfItsDeclaration(mixed $result, bool $mutating): void
    {
        $response = $this->call($this->operation('probe:answer', $mutating, static fn (): mixed => $result), $mutating ? 'POST' : 'GET');

        self::assertSame($mutating ? 201 : 200, $response->getStatusCode());
        self::assertSame($result, json_decode((string) $response->getBody(), true));
    }

    public function testTheRuleIsTheOneTheTerminalAlreadyHonours(): void
    {
        // One convention, read in one place: whatever the runner calls a negative verdict is what answers 409.
        foreach ([['ok' => false], ['ok' => true], ['ok' => 'false'], ['data' => 1], 'text', null, 3] as $result) {
            $status = $this->call($this->operation('probe:answer', false, static fn (): mixed => $result), 'GET')->getStatusCode();

            self::assertSame(OperationRunner::verdict($result) ? 200 : 409, $status, (string) json_encode($result));
        }
    }

    public function testAnOperationThatAsksForConfirmationAnswersNoOnlyAfterItRan(): void
    {
        $ran = 0;
        $operation = $this->operation('page:publish', true, static function () use (&$ran): array {
            ++$ran;

            return ['ok' => false, 'error' => 'the page has no title'];
        }, confirm: true);
        $projector = $this->projector($operation);

        $gate = $projector->handle($this->request($projector, 'page:publish', 'POST'));
        self::assertSame(428, $gate->getStatusCode(), 'the confirm gate comes first, and it is not a verdict');
        self::assertSame(0, $ran);

        $token = (string) json_decode((string) $gate->getBody(), true)['confirm_token'];
        $answer = $projector->handle($this->request($projector, 'page:publish', 'POST')->withHeader('Confirm-Token', $token));

        self::assertSame(409, $answer->getStatusCode());
        self::assertSame(1, $ran, 'the operation ran once: its «no» is its answer, not something that kept it from running');
        self::assertSame(['ok' => false, 'error' => 'the page has no title'], json_decode((string) $answer->getBody(), true));
    }

    public function testACallThatWasStoppedIsStillToldApartFromACallThatWasAnswered(): void
    {
        // Both are 409. The one a listener stopped never ran and carries the projector's own body; the one the
        // operation answered carries the operation's — which is where `ok: false` is.
        $stopped = $this->call($this->operation('page:publish', true, static fn (): array => throw new OperationStoppedException('the house is frozen for a release')));

        $body = json_decode((string) $stopped->getBody(), true);
        self::assertSame(409, $stopped->getStatusCode());
        self::assertSame('MILPA_OPERATION_STOPPED', $body['code'] ?? null);
        self::assertArrayNotHasKey('ok', $body);
    }

    public function testAnOperationThatThrowsIsStillAServerError(): void
    {
        $failed = $this->call($this->operation('page:publish', true, static fn (): array => throw new \RuntimeException('the disk is full')));

        self::assertSame(500, $failed->getStatusCode(), 'a failure is not a verdict: the operation never answered');
        self::assertSame('internal_error', json_decode((string) $failed->getBody(), true)['error'] ?? null);
    }

    public function testTheOperationIsStillRecordedAsHavingRun(): void
    {
        // The audit does not change: an operation that answered no RAN, and `operation.executed` says so.
        $events = [];
        $dispatcher = new class ($events) implements MilpaEventDispatcherInterface {
            /** @param list<string> $events */
            public function __construct(private array &$events)
            {
            }

            public function dispatch(string $eventName, array $payload = [], bool $async = false): void
            {
                $this->events[] = $eventName;
            }

            public function subscribe(string $eventName, callable $handler, int $priority = 0): void
            {
            }

            public function getSubscribers(string $eventName): array
            {
                return [];
            }

            public function hasSubscribers(string $eventName): bool
            {
                return false;
            }
        };
        $psr17 = new Psr17Factory();
        $operation = $this->operation('page:publish', true, static fn (): array => ['ok' => false, 'error' => 'no']);
        $projector = new HttpProjector([$operation], $this->createMock(DIContainerInterface::class), $psr17, $psr17, dispatcher: $dispatcher);

        self::assertSame(409, $projector->handle($this->request($projector, 'page:publish', 'POST'))->getStatusCode());
        self::assertContains(ConsoleEvents::EXECUTED, $events);
    }

    private function operation(string $name, bool $mutating, \Closure $handler, bool $confirm = false): Operation
    {
        return new Operation(
            name: $name,
            description: 'A probe',
            handler: $handler,
            inputSchema: ['type' => 'object'],
            mutating: $mutating,
            requiresConfirmation: $confirm,
            effects: $mutating
                ? new EffectProfile(
                    mutation: Mutation::Persistent,
                    externality: Externality::None,
                    reversibility: Reversibility::Guaranteed,
                    authority: Authority::Read,
                    subject: Subject::Data,
                    rollbackContract: 'probe:undo',
                )
                : EffectProfile::readOnly(),
        );
    }

    private function projector(Operation ...$operations): HttpProjector
    {
        $psr17 = new Psr17Factory();

        return new HttpProjector($operations, $this->createMock(DIContainerInterface::class), $psr17, $psr17);
    }

    private function call(Operation $operation, string $method = 'POST'): ResponseInterface
    {
        $projector = $this->projector($operation);

        return $projector->handle($this->request($projector, $operation->name, $method));
    }

    private function request(HttpProjector $projector, string $operation, string $method): ServerRequest
    {
        $route = null;
        foreach ($projector->routes() as $candidate) {
            if ($candidate->name === $operation) {
                $route = $candidate;
            }
        }
        self::assertNotNull($route);

        return (new ServerRequest($method, $route->path, ['Content-Type' => 'application/json'], '{}'))
            ->withAttribute(RouteResult::ATTRIBUTE, RouteResult::matched($route));
    }
}

<?php

declare(strict_types=1);

namespace Milpa\Console\Tests;

use Milpa\Command\Operation;
use Milpa\Console\CliProjector;
use Milpa\Console\Http\HttpProjector;
use Milpa\Console\McpProjector;
use Milpa\Console\Model\TuiOperationModel;
use Milpa\Console\TuiProjector;
use Milpa\Http\Routing\RouteResult;
use PHPUnit\Framework\Attributes\DataProvider;
use Milpa\Interfaces\Di\DIContainerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * One operation, one answer: every surface demands consent by the SAME rule.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────────────────────────
 *
 * Four surfaces asked `mutating && requiresConfirmation` and one asked `requiresConfirmation`
 * alone, so an operation that declared its change needs consent WITHOUT declaring itself mutating
 * ran unsigned on three surfaces and stopped on two. Nothing was broken in any single file — each
 * one is defensible on its own — and the divergence only exists in the space between them, which is
 * exactly the kind of gap no unit test of any one projector can see.
 *
 * The old rule also failed in the dangerous direction. `&&` can only ever SKIP consent an operation
 * explicitly asked for; it can never add one. This house has measured what that costs: an authority
 * gate you can walk around is a suggestion with better press.
 *
 * ── WHY IT ASKS EACH SURFACE IN ITS OWN LANGUAGE ────────────────────────────────────────────────
 *
 * A test that read the five call sites as text would pass the day someone copies the expression
 * into a sixth. So nothing here reads source: the CLI is asked for its `needsSignature`, the TUI
 * for whether it painted the signature badge, MCP for its `requiresConfirmation`, and HTTP is
 * actually dispatched and asked whether it answered 428. Four different observables, one question.
 */
final class ConsentPredicateTest extends TestCase
{
    /**
     * Four probes crossing the two flags — and C is the one that ever disagreed.
     *
     * @return array<string, array{0: bool, 1: bool}>
     */
    public static function sondas(): array
    {
        return [
            'A · mutating, no confirmation' => [true, false],
            'B · mutating and confirmation' => [true, true],
            'C · confirmation without mutating' => [false, true],
            'D · neither' => [false, false],
        ];
    }

    #[DataProvider('sondas')]
    public function testEverySurfaceDemandsConsentByTheSameRule(bool $mutating, bool $confirmation): void
    {
        $op = self::operacion($mutating, $confirmation);

        $answers = [
            'cli' => (new CliProjector())->project($op)->needsSignature,
            'tui' => self::tuiPaintedTheBadge((new TuiProjector())->project($op)),
            'mcp' => (new McpProjector())->project($op)->requiresConfirmation,
            'http' => $this->httpAnswered428($op),
        ];

        $distinct = array_unique(array_values($answers), \SORT_REGULAR);

        self::assertCount(
            1,
            $distinct,
            'the surfaces disagree about whether this operation needs consent: '
            . json_encode($answers, \JSON_THROW_ON_ERROR),
        );

        // And the shared answer has to be the operation's own declaration, not merely a consensus:
        // five surfaces agreeing to ignore `requiresConfirmation` would also pass the assertion above.
        self::assertSame($confirmation, $distinct[array_key_first($distinct)]);
    }

    /**
     * THE CONTROL: this comparison has to be able to come out «they disagree».
     *
     * Without it, a bug that made every observable return the same constant would read exactly like
     * a system in agreement — which is the failure mode this whole file exists to catch one level up.
     */
    public function testTheComparisonCanStillDetectDisagreement(): void
    {
        $answers = ['cli' => true, 'tui' => true, 'mcp' => false, 'http' => true];

        self::assertCount(2, array_unique(array_values($answers), \SORT_REGULAR));
    }

    private static function operacion(bool $mutating, bool $confirmation): Operation
    {
        return new Operation(
            name: 'sonda',
            description: 'A synthetic operation used only to compare surfaces',
            handler: static fn (array $input): array => ['ran' => true],
            inputSchema: ['type' => 'object', 'properties' => [], 'required' => []],
            mutating: $mutating,
            requiresConfirmation: $confirmation,
        );
    }

    /** The TUI does not carry a flag: it either painted the signature badge or it did not. */
    private static function tuiPaintedTheBadge(TuiOperationModel $model): bool
    {
        return str_contains(
            json_encode($model->toArray(), \JSON_THROW_ON_ERROR),
            '"id":"firma"',
        );
    }

    /** HTTP is dispatched for real; 428 is how it says «not without consent». */
    private function httpAnswered428(Operation $op): bool
    {
        $psr17 = new Psr17Factory();
        $projector = new HttpProjector([$op], $this->createMock(DIContainerInterface::class), $psr17, $psr17);

        $routes = $projector->routes();
        self::assertNotSame([], $routes, 'the probe did not synthesize a route');

        // The route travels in the attribute the router publishes, not in one named by hand: an
        // unmatched request comes back 404 and would read here as «this surface asks for nothing».
        $request = (new ServerRequest(
            $op->mutating ? 'POST' : 'GET',
            $routes[0]->path,
            ['Content-Type' => 'application/json'],
            '{}',
        ))->withAttribute(RouteResult::ATTRIBUTE, RouteResult::matched($routes[0]));

        $status = $projector->handle($request)->getStatusCode();
        self::assertNotSame(404, $status, 'the probe never reached the operation');

        return $status === 428;
    }
}

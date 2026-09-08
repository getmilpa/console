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

namespace Milpa\Console\Tests;

use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Console\CliRunner;
use Milpa\Console\Events\ConsoleEvents;
use Milpa\Console\OperationRunner;
use Milpa\Container\DIContainer;
use Milpa\Events\InterceptionSlot;
use Milpa\Interfaces\Event\DeclaredEvents;
use Milpa\Interfaces\Event\EventDeclaration;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use PHPUnit\Framework\TestCase;

/**
 * The falsifier of greenhouse decisions/0228 for this package: the emitter declares every event it
 * dispatches, to the dispatcher, at the site where it receives it — and the declared set is exactly
 * the one this test expects, so a deleted or renamed declaration goes red instead of silent.
 *
 * The instrument is a SPY dispatcher that both counts declarations and records dispatches. The real
 * code path is driven twice — the runner itself with a real container resolving a `[Class, method]`
 * handler, and the CLI surface that hands its dispatcher to the runner — so that what is compared is
 * what was actually declared against what was actually dispatched, never a reading of the source.
 */
final class TheEmitterDeclaresEveryEventItDispatchesTest extends TestCase
{
    /**
     * The exact names this package dispatches. Hardcoded on purpose: the declarations holder cannot
     * be the oracle for itself.
     *
     * @var list<string>
     */
    private const array EXPECTED = ['operation.executing', 'operation.executed'];

    public function test_every_name_dispatched_is_declared_and_the_declared_set_is_exactly_the_expected_one(): void
    {
        $spy = $this->spy();
        $this->driveTheRealCodePath($spy);

        $declared = array_map(static fn (EventDeclaration $d): string => $d->name, $spy->declared());

        self::assertSame(self::EXPECTED, $spy->dispatched(), 'the code path dispatched both names, in firing order');
        self::assertSame([], array_diff($spy->dispatched(), $declared), 'no name was dispatched without a declaration');
        self::assertSame(self::EXPECTED, $declared, 'the declared set is exactly the expected one — a deleted or renamed declaration lands here');
    }

    public function test_each_declaration_describes_the_payload_the_spy_saw_for_that_name(): void
    {
        $spy = $this->spy();
        $this->driveTheRealCodePath($spy);

        foreach ($spy->declared() as $declaration) {
            $payload = $spy->payloads[$declaration->name] ?? null;
            self::assertIsArray($payload, "«{$declaration->name}» was declared and never dispatched");

            self::assertSame(OperationRunner::class, $declaration->dispatchedBy);
            self::assertArrayHasKey($declaration->subjectKey, $payload, "«{$declaration->name}»: the declared subject key is not in the payload");
            self::assertNotNull($declaration->subjectType);
            self::assertInstanceOf($declaration->subjectType, $payload[$declaration->subjectKey], "«{$declaration->name}»: the subject is not of the declared type");
            self::assertFalse($declaration->mutable, 'the subjects are readonly value objects; nothing here is meant to be changed in place');

            $carriesSlot = ($payload['slot'] ?? null) instanceof InterceptionSlot;
            self::assertSame($carriesSlot, $declaration->interceptable, "«{$declaration->name}»: `interceptable` disagrees with whether the payload carries an InterceptionSlot");
        }
    }

    public function test_the_emitter_declares_where_it_receives_the_dispatcher_before_anything_runs(): void
    {
        $spy = $this->spy();
        new OperationRunner(new DIContainer(), $spy);

        self::assertSame([], $spy->dispatched(), 'constructing dispatches nothing');
        self::assertSame(
            self::EXPECTED,
            array_map(static fn (EventDeclaration $d): string => $d->name, $spy->declared()),
            'the declarations arrive with the dispatcher, not with the first dispatch',
        );
    }

    public function test_the_declarations_are_built_from_the_same_constants_the_dispatch_uses(): void
    {
        $names = array_map(static fn (EventDeclaration $d): string => $d->name, ConsoleEvents::declarations());

        self::assertSame([ConsoleEvents::EXECUTING, ConsoleEvents::EXECUTED], $names);
    }

    /** CONTROL: a dispatcher that does not count declarations is asked nothing, and the same path runs. */
    public function test_a_dispatcher_that_does_not_count_declarations_runs_the_same_path_untouched(): void
    {
        $seen = [];
        $plain = new class ($seen) implements MilpaEventDispatcherInterface {
            /** @param list<string> $seen */
            public function __construct(private array &$seen)
            {
            }

            public function dispatch(string $eventName, array $payload = [], bool $async = false): void
            {
                $this->seen[] = $eventName;
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

        self::assertNotInstanceOf(DeclaredEvents::class, $plain, 'the control must not count declarations');

        $this->driveTheRealCodePath($plain);

        self::assertSame([...self::EXPECTED, ...self::EXPECTED], $seen, 'both drives dispatched both names, with nobody to declare to');
    }

    /**
     * The runner with a real container resolving a `[Class, method]` handler, then the CLI surface
     * that hands its own dispatcher to the runner. Two drives, one dispatcher.
     */
    private function driveTheRealCodePath(MilpaEventDispatcherInterface $dispatcher): void
    {
        $handler = new class () {
            /**
             * @param array<string, mixed> $input
             *
             * @return array<string, mixed>
             */
            public function handle(array $input): array
            {
                return ['ok' => true] + $input;
            }
        };
        $container = new DIContainer();
        $container->registerService($handler::class, $handler);

        $byClass = $this->operation([$handler::class, 'handle']);
        $result = (new OperationRunner($container, $dispatcher))->run($byClass, ['x' => 'y'], 'cli');
        self::assertSame(['ok' => true, 'x' => 'y'], $result, 'the runner drive really ran the handler');

        $byClosure = $this->operation(static fn (array $input): array => ['ok' => true]);
        $exit = (new CliRunner(dispatcher: $dispatcher))->run($byClosure, [], $container, static function (string $line): void {
        });
        self::assertSame(0, $exit, 'the CLI drive really ran the operation');
    }

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     */
    private function operation(callable|array $handler): Operation
    {
        return new Operation(
            name: 'probe',
            description: 'A read-only probe',
            handler: $handler,
            inputSchema: ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]],
            effects: new EffectProfile(
                mutation: Mutation::None,
                externality: Externality::None,
                reversibility: Reversibility::NotApplicable,
                authority: Authority::Read,
                subject: Subject::None,
            ),
        );
    }

    /**
     * A dispatcher that counts what was declared to it and records what was dispatched through it.
     *
     * @return MilpaEventDispatcherInterface&DeclaredEvents&object{payloads: array<string, array<string, mixed>>}
     */
    private function spy(): MilpaEventDispatcherInterface&DeclaredEvents
    {
        return new class () implements MilpaEventDispatcherInterface, DeclaredEvents {
            /** @var array<string, EventDeclaration> */
            private array $declared = [];

            /** @var list<string> */
            private array $dispatched = [];

            /** @var array<string, array<string, mixed>> the first payload seen per name */
            public array $payloads = [];

            public function declare(EventDeclaration ...$events): void
            {
                foreach ($events as $event) {
                    $this->declared[$event->name] ??= $event;
                }
            }

            public function declared(): array
            {
                return array_values($this->declared);
            }

            public function dispatched(): array
            {
                return $this->dispatched;
            }

            public function dispatch(string $eventName, array $payload = [], bool $async = false): void
            {
                if (!\in_array($eventName, $this->dispatched, true)) {
                    $this->dispatched[] = $eventName;
                    $this->payloads[$eventName] = $payload;
                }
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
    }
}

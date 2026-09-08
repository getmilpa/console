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
use Milpa\Container\DIContainer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The `--sign` message says the fact the gate read, never one the operation may not have declared
 * (greenhouse decisions/0227): a mutation «mutates», a read with no EffectProfile «never declared its
 * effects», a read that declares consent «demands consent». A declared read runs and is told nothing.
 */
final class TheSignMessageSaysTheFactTheGateReadTest extends TestCase
{
    /** @var list<string> */
    private array $out = [];

    #[Test]
    public function a_mutation_is_told_it_mutates(): void
    {
        $this->run_($this->operation('lab:burn', mutating: true, effects: new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::ManualRecovery, Authority::Privileged, subject: Subject::Executable)));

        self::assertStringContainsString('This operation mutates and needs your authorization. Re-run with --sign.', $this->printed());
    }

    #[Test]
    public function a_read_with_no_profile_is_told_it_never_declared_its_effects(): void
    {
        $this->run_($this->operation('lab:peek', mutating: false, effects: null));

        self::assertStringContainsString('never declared its effects (unclassified counts as the maximum) and needs your authorization', $this->printed());
        self::assertStringNotContainsString('mutates', $this->printed(), 'a read is not told it mutates');
    }

    #[Test]
    public function a_read_that_declares_consent_is_told_it_demands_it(): void
    {
        $this->run_($this->operation('lab:confirmed', mutating: false, effects: EffectProfile::readOnly(), requiresConfirmation: true));

        self::assertStringContainsString('This operation demands consent and needs your authorization. Re-run with --sign.', $this->printed());
    }

    #[Test]
    public function the_control_a_declared_read_runs_and_is_told_nothing(): void
    {
        $exit = $this->run_($this->operation('lab:declared', mutating: false, effects: EffectProfile::readOnly()));

        self::assertSame(0, $exit);
        self::assertStringNotContainsString('--sign', $this->printed());
    }

    private function operation(string $name, bool $mutating, ?EffectProfile $effects, bool $requiresConfirmation = false): Operation
    {
        return new Operation(
            name: $name,
            description: 'a probe',
            handler: static fn (array $input): array => ['ok' => true],
            inputSchema: ['type' => 'object', 'properties' => [], 'required' => []],
            mutating: $mutating,
            requiresConfirmation: $requiresConfirmation,
            effects: $effects,
        );
    }

    private function run_(Operation $operation): int
    {
        $this->out = [];
        // The message is printed before anything is resolved: an empty real container is enough.
        $container = new DIContainer();

        return (new CliRunner())->run($operation, [], $container, function (string $line): void {
            $this->out[] = $line;
        });
    }

    private function printed(): string
    {
        return implode("\n", $this->out);
    }
}

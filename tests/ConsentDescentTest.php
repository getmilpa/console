<?php

declare(strict_types=1);

namespace Milpa\Console\Tests;

use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\Descent;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Console\Consent;
use PHPUnit\Framework\TestCase;

/**
 * The battery greenhouse evidence/0152 froze before this class could read an argument.
 *
 * `command v0.8.0` shipped the descent field, `capabilities:enable` declared one with two
 * measurements behind it, and five green cases proved the field itself — and a grep for `forCall`
 * across the whole vendor tree returned one result: its own definition. The field existed and
 * nothing consulted it, because consent was decided from the OPERATION and never from the CALL.
 *
 * A FIELD IS NOT A MECHANISM. That is greenhouse MILPA-G002 one floor down from where it was
 * written, and it is the third time this shape has been found here: a rule with no wiring, a law
 * with no gate, and now a field with no reader. All three share the same tell — the artifact's own
 * unit test passes while the path that would use it does not exist.
 *
 * The second case is the control. If the same call demands the same thing with and without the
 * argument, nothing here is measuring the descent; it is measuring something that already happened.
 */
final class ConsentDescentTest extends TestCase
{
    /** 1 · with the argument, the declared descent lands and the operation runs on its own. */
    public function testTheArgumentBringsTheCeilingDown(): void
    {
        self::assertFalse(Consent::demanded($this->conDescenso(), ['dry_run' => true]));
    }

    /**
     * 2 · THE CONTROL: the same operation without the argument still demands consent.
     *
     * Without this, a `demanded()` that had simply stopped working would pass case one and read as
     * a descent that works.
     */
    public function testWithoutTheArgumentTheSameOperationStillDemandsConsent(): void
    {
        self::assertTrue(Consent::demanded($this->conDescenso(), ['dry_run' => false]));
    }

    /**
     * 3 · no arguments at all — a catalogue surface — and the ceiling stays up.
     *
     * Four of the seven call sites list, project or paint operations with no invocation in hand, so
     * there are no arguments to read. Failing upward there is rule 3 of greenhouse decisions/0029
     * read literally: a descent with nothing holding it up lowers nothing. A conservative listing
     * lies toward the safe side; an optimistic one promises not to ask and then asks.
     */
    public function testWithNoArgumentsTheCeilingStaysUp(): void
    {
        self::assertTrue(Consent::demanded($this->conDescenso()));
    }

    /**
     * 4 · a descent with no reason lowers nothing, even with the argument present.
     *
     * greenhouse decisions/0029: lowering inverts the incentive that makes `escalatesOn` safe, so a
     * declaration that carries nothing to hold it up is not a descent at all.
     */
    public function testADescentWithoutAReasonLowersNothing(): void
    {
        self::assertTrue(Consent::demanded($this->conDescenso(porque: ''), ['dry_run' => true]));
    }

    private function conDescenso(string $porque = 'the handler returns what it would run without running it'): Operation
    {
        return new Operation(
            name: 'sonda',
            description: 'a probe that declares a descent on one argument',
            handler: static fn (): array => [],
            effects: new EffectProfile(
                mutation: Mutation::Persistent,
                externality: Externality::ThirdParty,
                reversibility: Reversibility::Compensatable,
                authority: Authority::Privileged,
                subject: Subject::Executable,
                rollbackContract: 'synthetic probe',
                descents: [new Descent(
                    argument: 'dry_run',
                    whenValue: true,
                    to: new EffectProfile(
                        mutation: Mutation::None,
                        externality: Externality::None,
                        reversibility: Reversibility::Guaranteed,
                        authority: Authority::Read,
                        subject: Subject::None,
                        rollbackContract: 'nothing ran, so there is nothing to undo',
                    ),
                    because: $porque,
                )],
            ),
        );
    }
}

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
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Console\Consent;
use PHPUnit\Framework\TestCase;

/**
 * Rule S2, now that it runs: consent is derived from the ceiling, not only declared by hand.
 *
 * greenhouse decisions/0019 decided it and decisions/0028 corrected it — both axes compare by
 * WEIGHT. The rule shipped comparing subject by membership and authority by identity, so
 * Authority::Unknown, the highest weight and the value GOV-05 counts as the maximum, fell outside
 * the rule it was most meant for.
 *
 * The last case is the control and it is the one that matters: an operation NOBODY classified has to
 * demand consent. If the unclassified were let through, the rule would reward not declaring, and
 * every ceiling in this framework would become optional in practice.
 */
final class ConsentByCeilingTest extends TestCase
{
    /** Executable code under a privileged authority demands consent, with nobody declaring a flag. */
    public function testAnExecutableSubjectUnderPrivilegedAuthorityDemandsConsent(): void
    {
        self::assertTrue(Consent::demanded($this->conTecho(Subject::Executable, Authority::Privileged)));
    }

    /** Below either axis it does not: the rule needs both, which is what keeps it from swallowing the catalogue. */
    public function testTheSameSubjectUnderAMilderAuthorityDoesNot(): void
    {
        self::assertFalse(Consent::demanded($this->conTecho(Subject::Executable, Authority::WriteAsUser)));
    }

    public function testAMilderSubjectUnderPrivilegedAuthorityDoesNot(): void
    {
        self::assertFalse(Consent::demanded($this->conTecho(Subject::Configuration, Authority::Privileged)));
    }

    /**
     * THE CORRECTION FROM decisions/0028: Unknown weighs MORE than Privileged, so it is inside.
     *
     * Compared by identity — the way the rule shipped — this case walks straight through, and it is
     * precisely the case GOV-05 exists for.
     */
    public function testUnknownAuthorityIsInsideTheRuleRatherThanOutsideIt(): void
    {
        self::assertTrue(Consent::demanded($this->conTecho(Subject::Executable, Authority::Unknown)));
    }

    /** THE CONTROL: what nobody classified carries the maximum, so it asks. */
    public function testAnUnclassifiedOperationDemandsConsent(): void
    {
        $sinDeclarar = new Operation(
            name: 'sin-techo',
            description: 'nobody said what this does',
            handler: static fn (): array => [],
        );

        self::assertTrue(
            Consent::demanded($sinDeclarar),
            'letting the unclassified through would reward not declaring, and make every ceiling optional',
        );
    }

    /** And the hand-declared flag still wins on its own — S2 adds to it, it does not replace it. */
    public function testTheDeclaredFlagStillDemandsConsentOnItsOwn(): void
    {
        $op = new Operation(
            name: 'declarada',
            description: 'someone said this one asks',
            handler: static fn (): array => [],
            requiresConfirmation: true,
            effects: new EffectProfile(
                mutation: Mutation::None,
                externality: \Milpa\Command\Effect\Externality::None,
                reversibility: \Milpa\Command\Effect\Reversibility::Guaranteed,
                authority: Authority::Read,
                subject: Subject::None,
                rollbackContract: 'nothing is written',
            ),
        );

        self::assertTrue(Consent::demanded($op));
    }

    private function conTecho(Subject $subject, Authority $authority): Operation
    {
        return new Operation(
            name: 'sonda',
            description: 'a probe carrying exactly the ceiling under test',
            handler: static fn (): array => [],
            effects: new EffectProfile(
                mutation: Mutation::Persistent,
                externality: \Milpa\Command\Effect\Externality::None,
                reversibility: \Milpa\Command\Effect\Reversibility::Guaranteed,
                authority: $authority,
                subject: $subject,
                rollbackContract: 'synthetic probe',
            ),
        );
    }
}

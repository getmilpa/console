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

namespace Milpa\Console;

use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;

/**
 * Whether an operation may run without someone authorizing THIS call — asked in one place.
 *
 * ── WHY A CLASS FOR ONE EXPRESSION ──────────────────────────────────────────────────────────────
 *
 * Because the expression was written five times and one copy said something else. Four surfaces
 * asked `mutating && requiresConfirmation` and the MCP projection asked `requiresConfirmation`
 * alone, so an operation that declared its change needs consent WITHOUT declaring itself mutating
 * ran unsigned on three surfaces and stopped on two. No file was wrong on its own; the divergence
 * lived in the space between them, where no unit test of any single projector could reach it.
 *
 * A named predicate is not a style preference here. It is the difference between a rule and five
 * agreements, and this house has measured what the second one costs.
 *
 * ── WHY THE `mutating` HALF WENT AWAY AND NOT THE OTHER ─────────────────────────────────────────
 *
 * The two candidate rules fail in opposite directions, and only one of them fails safely.
 * `mutating && requiresConfirmation` can only ever SKIP consent that an operation explicitly asked
 * for — it can never add one — so every disagreement it produces runs something a declaration meant
 * to stop. An authority gate you can walk around is a suggestion with better press.
 *
 * The change is also provably inert on arrival: of the thirty-one operations a fully equipped app
 * offers today, exactly one demands confirmation and it mutates, so no live operation sits in the
 * cell that used to diverge. What was being fixed is not a broken call — it is the first one to
 * land there, which would have looked correct on every surface its author tried.
 */
final class Consent
{
    /**
     * True when this call needs someone to authorize it before it runs.
     *
     * The operation's own declaration is the whole answer. Nothing about the caller, the surface or
     * the arguments takes part: a surface decides HOW consent is collected — a signature on the
     * terminal, a confirmation token over MCP and HTTP, a badge and a keystroke in the TUI — and
     * never WHETHER it is needed.
     */
    /**
     * @param array<string, mixed> $arguments the invocation's arguments, empty on catalogue surfaces
     */
    public static function demanded(Operation $op, array $arguments = []): bool
    {
        if ($op->requiresConfirmation) {
            return true;
        }

        // RULE S2, wired at last (greenhouse decisions/0019, corrected by decisions/0028).
        //
        //   Subject at Executable or above  AND  Authority at Privileged or above  ⇒  consent
        //
        // What is being changed decides this, not how much: an operation that changes WHICH CODE WILL
        // RUN, wielding an authority the caller does not hold on their own, is asking for something
        // nobody can take back by reading the result.
        //
        // BOTH AXES COMPARE BY WEIGHT, and that is the correction. The rule shipped comparing subject
        // by membership and authority by identity, so `Authority::Unknown` — weight 4, the highest,
        // the value GOV-05 says counts as the maximum — fell OUTSIDE the rule it was most meant for.
        // Weight is the scale the enum already declares, and it is the only form that stays right
        // when a level is added: enumerating the dangerous forgets the new one, negating the safe
        // catches a new mild one by mistake, and the scale does neither.
        // THE CEILING IS RESOLVED FOR THIS CALL, not for the operation in the abstract.
        //
        // S2 judges the operation, so a rehearsal carried the same ceiling as the real thing and
        // `capabilities:enable --dry-run --json` asked for a signature it had nothing to sign for.
        // `command v0.8.0` added the descent field for exactly this, and greenhouse evidence/0152
        // measured that nothing was reading it: the field existed, and a field is not a mechanism.
        //
        // WITH NO ARGUMENTS THE CEILING STAYS UP, and that is deliberate. Four of this package's
        // call sites list, project or paint operations with no invocation in hand, so there is
        // nothing to read and nothing may come down — greenhouse decisions/0029 rule 3, that a
        // descent with nothing holding it up lowers nothing. A conservative listing errs toward the
        // safe side; an optimistic one promises not to ask and then asks.
        $techo = $op->effectCeiling()->forCall($arguments);

        return $techo->subject->weight() >= Subject::Executable->weight()
            && $techo->authority->weight() >= Authority::Privileged->weight();
    }
}

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
    public static function demanded(Operation $op): bool
    {
        return $op->requiresConfirmation;
    }
}

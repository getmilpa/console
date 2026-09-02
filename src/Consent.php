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

use Milpa\Command\Consent\ConsentGrant;
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
     * Does this call demand a human signature before it runs?
     *
     * One rule for every surface, resolved for the CALL rather than for the operation in the
     * abstract: an operation may declare that a given argument brings its ceiling down, and this is
     * where that declaration is read. Surfaces holding no invocation pass no arguments, and there
     * nothing comes down.
     *
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
        //
        // AND SINCE `command v0.10.0` THE CEILING IS ASKED OF THE OPERATION, not of its profile.
        // greenhouse decisions/0050 made a descent depend on a certificate bound to the handler about
        // to run, and the operation is the only place holding both. Asking the profile alone would
        // hand it a question it cannot answer, and it would answer honestly: no descent.
        $techo = $op->ceilingForCall($arguments);

        return $techo->subject->weight() >= Subject::Executable->weight()
            && $techo->authority->weight() >= Authority::Privileged->weight();
    }

    /**
     * ¿Está SATISFECHA la demanda de esta llamada por una autoridad ya en mano? (0187 D-01)
     *
     * `demanded()` dice, surface-agnóstico, SI hace falta que alguien autorice. Satisfacerla, en
     * cambio, se había vuelto surface-específico: el CLI la limpia con una firma gpg, la sesión con
     * un grant de `perm:` — dos mecanismos disjuntos de una misma demanda. Un sí humano verificado,
     * grabado por un canal que no firma por CLI, no limpiaba una demanda de firma aunque FUERA la
     * autoridad que la operación pedía. «Una autoridad, muchas proyecciones» quedaba roto: la
     * autoridad del humano es una, pero sólo se aceptaba la proyección-firma del CLI.
     *
     * Este predicado la cierra: una demanda está satisfecha cuando NO hay demanda, o cuando algún
     * grant en mano {@see ConsentGrant::admits()} la llamada EXACTA — es decir, la cubre Y trae una
     * prueba viva. La firma gpg es UNA prueba así (acuñable como IntentGrant desde su
     * `GrantedAuthorization`); la respuesta humana verificada de un canal de grabación es otra. Un
     * grant sin prueba —el sí de sesión ordinario, o una intención de MODELO no verificada— cubre
     * pero NO admite, así que jamás compra una demanda de firma: la falla-cerrada se conserva.
     *
     * La sesión es REQUERIDA, sin default: una autoridad que limpia un privilegio no puede caer
     * abierta porque quien llama olvidó enhebrarla. `null` es una respuesta válida —«sin sesión»— y
     * entonces ningún grant proof-backed admite ({@see ConsentGrant::admits()} exige sesión exacta),
     * pero es una respuesta que el llamador da a propósito, no un default que se le escapa.
     *
     * @param array<string, mixed>   $arguments los argumentos de ESTA invocación
     * @param iterable<ConsentGrant> $grants    los sí ya en mano esta sesión
     */
    public static function satisfiedBy(
        Operation $op,
        array $arguments,
        iterable $grants,
        ?string $session,
    ): bool {
        if (! self::demanded($op, $arguments)) {
            return true;
        }

        foreach ($grants as $grant) {
            if ($grant->admits($op->name, $arguments, $session)) {
                return true;
            }
        }

        return false;
    }
}

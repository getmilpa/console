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

namespace Milpa\Console\Http;

/**
 * El host declaró una operación protegida y no cableó con qué protegerla.
 *
 * Es un 500 y no un 401/403 a propósito, y la distinción es de Rod: un 4xx culpa a quien llamó, y
 * quien llamó no hizo nada mal — el servidor expuso una operación con scopes y se quedó sin la
 * política que los revisa. Callarlo y dejar pasar sería peor todavía: la operación correría sin que
 * nadie mire sus scopes, que es el agujero que esta capa cerró.
 */
final class UnguardedOperationException extends \RuntimeException
{
    /**
     * La negativa cuando la operación exige scopes y nadie los puede revisar.
     *
     * @param list<string> $scopes los que la operación declaró, para que el mensaje diga cuáles
     */
    public static function scoped(string $operation, array $scopes): self
    {
        return new self(\sprintf(
            'La operación «%s» exige los scopes [%s] y este host no cableó una %s. '
            . 'Registra una política (milpa/admin publica la que usa milpa/auth) o quítale los scopes.',
            $operation,
            implode(', ', $scopes),
            OperationHttpPolicy::class,
        ));
    }

    /** La misma negativa para una operación tipada por permiso en vez de por scopes. */
    public static function permissioned(string $operation, string $permission): self
    {
        return new self(\sprintf(
            'La operación «%s» exige el permiso «%s» y este host no cableó una %s. '
            . 'Registra una política (milpa/admin publica la que usa milpa/auth) o quítale el permiso.',
            $operation,
            $permission,
            OperationHttpPolicy::class,
        ));
    }
}

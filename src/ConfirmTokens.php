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

/**
 * A one-time confirm-token store for the HTTP two-step confirm gate: {@see HttpProjector} issues a token
 * on the 428 challenge and consumes it when the client repeats the request. Two implementations exist and
 * the difference is lifetime, which is why this is an interface: {@see ConfirmTokenStore} keeps tokens in
 * a per-process array (fine for one long-lived process, e.g. tests), and {@see FileConfirmTokenStore}
 * persists them so the gate can COMPLETE across the separate processes a real HTTP deployment spawns
 * per request (php-fpm workers, `php -S` — greenhouse evidence/0359, the pin's web-surface slice).
 */
interface ConfirmTokens
{
    /** Mints a one-time token bound to `$operation`, valid for the store's TTL. */
    public function issue(string $operation): string;

    /**
     * Spends the token, answering whether it was valid for THIS operation and still fresh. The token is
     * dropped even on mismatch: a wrong guess must not leave a live token behind for a second attempt.
     */
    public function consume(string $token, string $operation): bool;
}

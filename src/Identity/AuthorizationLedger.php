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

namespace Milpa\Console\Identity;

use Milpa\Plugin\Contracts\AppRoot;

/**
 * Where an app keeps the record of which authorizations have already been spent.
 *
 * It is one expression, and it is a class so that it can be pinned by a test. It used to be
 * `dirname(__DIR__, 2) . '/storage/authorizations'` written inline at the point of use, which
 * asked the FILE where it was rather than asking the APP where it lives — and the two answers
 * differ exactly where it matters: `platform/` from a checkout of this package, and
 * `vendor/milpa/` from an installed app. So replay protection was being written inside `vendor/`,
 * measured landing at `vendor/milpa/storage/authorizations/<nonce>` after a real signed run
 * (greenhouse `evidence/0990`).
 *
 * That is not a cosmetic misplacement. `rm -rf vendor && composer install` is this ecosystem's
 * routine recovery, and it erases the one record that stops a still-fresh authorization from being
 * presented a second time. The freshness window bounds the damage to two minutes, not to zero: a
 * guard whose state lives in the directory everybody treats as disposable stops guarding without
 * ever saying so.
 */
final readonly class AuthorizationLedger
{
    /**
     * The ledger directory under the root the app declared.
     *
     * Alongside `storage/identity/enrollments.json`, which the same door already resolves from the
     * app root — one place an app keeps what proves who did what, instead of two.
     */
    public static function under(AppRoot $root): string
    {
        return rtrim($root->path, '/') . '/storage/authorizations';
    }
}

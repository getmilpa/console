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
 * Why `--sign` produced no signature, said to the person at the keys: one fact and one way out.
 *
 * «Nothing was signed» is true in every case and useful in none of them. The first person who runs
 * `--sign` on a new house usually has no key at all, and the sentence that told them «either declined,
 * or no usable key» made them guess which (greenhouse decisions/0551). This names the case the signer
 * could tell apart. It never authorizes anything: it is read only after the call was already refused.
 */
final readonly class SigningFailure
{
    /** gpg itself could not be run on this machine. */
    public const string NO_GPG = 'no_gpg';

    /** The keyring this terminal reads holds no key that can sign — none, or only expired/revoked ones. */
    public const string NO_KEY = 'no_key';

    /** A key that can sign is there, and gpg still returned no signature: the prompt was declined or never shown. */
    public const string NOT_GIVEN = 'not_given';

    /**
     * @param string       $kind   one of the constants above
     * @param string       $reason the fact, in one sentence
     * @param list<string> $remedy what to do about it, one line each; a command is a line of its own
     */
    public function __construct(
        public string $kind,
        public string $reason,
        public array $remedy,
    ) {
    }
}

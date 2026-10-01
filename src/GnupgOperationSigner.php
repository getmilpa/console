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

use Milpa\ToolRuntime\Identity\OperationAuthorization;

/**
 * Asks the operator's key to authorize one specific call, through the `gpg` on this machine.
 *
 * Lives in the host, not in the runtime, and the split is the same one the rest of the framework
 * uses: verifying is a policy decision and belongs where policy lives; signing is a fact about this
 * machine — which key, which agent, which card — and belongs where the machine is configured.
 *
 * What it signs is the whole call, never the operation's name alone. That is the difference between
 * this and the `--yes` it replaces: a flag consents to *removing a plugin*, so the same yes covers
 * removing any plugin, on any host, at any later moment. These bytes name one plugin, on this host,
 * in this minute.
 *
 * **What a signature proves here, and what it does not.** It always proves the call was authorized
 * by the holder of that key, and it always binds the target — those hold no matter how gpg-agent is
 * configured. What it does *not* prove on its own is human presence at this instant: with a cached
 * passphrase and a card that does not demand touch, signing needs no hands. Presence comes from the
 * key's own policy, so the gate is worth exactly what the card is set to require. Said plainly here
 * rather than implied, because "it is signed" invites a stronger reading than the mechanism earns.
 */
final class GnupgOperationSigner implements OperationSigner, ExplainsSigningFailure
{
    /** The one line that creates a signing key; gpg asks for its passphrase itself. */
    public const string CREATE_A_KEY = "gpg --quick-gen-key 'Your Name <you@example.com>' ed25519 sign never";

    public function __construct(
        private readonly string $gpgBinary = 'gpg',
        private readonly ?string $keyId = null,
    ) {
    }

    /**
     * Builds the authorization for this call and returns it with its detached signature.
     *
     * @param array<string, mixed> $arguments the derived input, exactly as the handler will receive it
     *
     * @return array{0: string, 1: string}|null the canonical payload and its signature, or null when
     *                                          signing failed — a refused card, a missing key, no agent
     */
    public function sign(string $operation, array $arguments, string $host, int $now): ?array
    {
        $authorization = new OperationAuthorization(
            operation: $operation,
            arguments: $arguments,
            host: $host,
            issuedAt: gmdate('c', $now),
            // Random rather than sequential: a predictable nonce lets someone pre-compute the
            // ledger entry for an authorization the operator has not made yet, and burn it.
            nonce: bin2hex(random_bytes(16)),
        );

        $payload = $authorization->canonical();

        $payloadFile = tempnam(sys_get_temp_dir(), 'milpa-authz-');
        if ($payloadFile === false) {
            return null;
        }

        try {
            file_put_contents($payloadFile, $payload);

            $key = $this->keyId !== null && $this->keyId !== ''
                ? ' --local-user ' . escapeshellarg($this->keyId)
                : '';

            // No --batch: signing may need a passphrase or a card touch, and suppressing the prompt
            // would turn "the operator declined" into "the tool is broken".
            $command = escapeshellcmd($this->gpgBinary)
                . ' --armor --detach-sign' . $key . ' --output - '
                . escapeshellarg($payloadFile) . ' 2>/dev/null';

            $signature = shell_exec($command);

            if (!\is_string($signature) || !str_contains($signature, 'BEGIN PGP SIGNATURE')) {
                return null;
            }

            return [$payload, $signature];
        } finally {
            @unlink($payloadFile);
        }
    }

    /**
     * Asks gpg, after the fact, which of the three refusals this was — and says the one way out of it.
     *
     * The signing call keeps its stderr closed (a passphrase prompt must reach the terminal, not this
     * process), so the reason is read with a second, read-only question: which secret keys does the
     * keyring this terminal reads hold, and can any of them sign? Measured on a new house (greenhouse
     * evidence/1085): an empty keyring and a declined prompt printed the same two lines, and the first
     * person to type `--sign` could not tell which one they had.
     */
    public function whyNotSigned(): SigningFailure
    {
        $keyring = $this->keyring();
        $wanted = $this->keyId !== null && $this->keyId !== '' ? ' ' . escapeshellarg($this->keyId) : '';
        $lines = [];
        $code = 0;
        @exec(
            escapeshellcmd($this->gpgBinary) . ' --batch --with-colons --list-secret-keys' . $wanted . ' 2>/dev/null',
            $lines,
            $code,
        );

        // 126/127 are the shell's own answers: nothing called «gpg» could be executed.
        if ($code === 126 || $code === 127) {
            return new SigningFailure(
                SigningFailure::NO_GPG,
                "gpg could not be run on this machine (looked for «{$this->gpgBinary}»).",
                [
                    'Install GnuPG, create a key once, then run the same command again:',
                    '  ' . self::CREATE_A_KEY,
                ],
            );
        }

        $signer = self::firstKeyThatCanSign($lines);
        if ($signer === null) {
            $which = $wanted !== '' ? "no key «{$this->keyId}» that can sign" : 'no key that can sign';

            return new SigningFailure(
                SigningFailure::NO_KEY,
                ucfirst($which) . " is in the keyring this terminal reads ({$keyring}).",
                [
                    'Create one once, then run the same command again:',
                    '  ' . self::CREATE_A_KEY,
                    'If your key lives in another keyring, set GNUPGHOME to it. A resident signs with a keyring of its own.',
                ],
            );
        }

        return new SigningFailure(
            SigningFailure::NOT_GIVEN,
            "{$signer} can sign, but gpg returned no signature: the passphrase or card prompt was declined, or could not be shown.",
            ['Run it again from a terminal where gpg can ask you (export GPG_TTY=$(tty)), and confirm at the prompt or the card.'],
        );
    }

    /** Where gpg looks: GNUPGHOME when set, else the home directory's .gnupg. */
    private function keyring(): string
    {
        $home = getenv('GNUPGHOME');
        if (\is_string($home) && $home !== '') {
            return $home;
        }
        $user = getenv('HOME');

        return (\is_string($user) && $user !== '' ? rtrim($user, '/') : '~') . '/.gnupg';
    }

    /**
     * The user id of the first secret key that can sign, from gpg's `--with-colons` listing, or null.
     *
     * A `sec` record's 12th field holds the key's capabilities; an upper-case `S` means the key as a
     * whole can sign (a subkey may carry it). Expired (`e`), revoked (`r`), disabled (`d`) or invalid (`i`)
     * keys cannot, whatever they declare.
     *
     * @param list<string> $lines
     */
    private static function firstKeyThatCanSign(array $lines): ?string
    {
        $found = false;
        $current = false;
        foreach ($lines as $line) {
            $fields = explode(':', $line);
            if ($fields[0] === 'sec') {
                $current = !\in_array($fields[1] ?? '', ['e', 'r', 'd', 'i'], true)
                    && str_contains($fields[11] ?? '', 'S');
                $found = $found || $current;
                continue;
            }
            if ($current && $fields[0] === 'uid' && ($fields[9] ?? '') !== '') {
                return $fields[9];
            }
        }

        return $found ? 'a key' : null;
    }
}

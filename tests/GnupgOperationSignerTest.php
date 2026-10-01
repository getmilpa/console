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

use Milpa\Console\GnupgOperationSigner;
use Milpa\Console\SigningFailure;
use Milpa\ToolRuntime\Identity\OperationAuthorization;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Asking the machine's key to authorize a call — including when it says no.
 *
 * The binary is injected so the refusal path is reachable. It is the branch that runs whenever an
 * operator changes their mind at the card, and with a real key in the loop it would be the one
 * branch no test could ever visit.
 */
#[CoversClass(GnupgOperationSigner::class)]
final class GnupgOperationSignerTest extends TestCase
{
    /** @var list<string> */
    private array $scripts = [];

    protected function tearDown(): void
    {
        foreach ($this->scripts as $script) {
            @unlink($script);
        }
    }

    private function gpgPrinting(string $output): string
    {
        $path = sys_get_temp_dir() . '/fake-sign-' . bin2hex(random_bytes(6));
        file_put_contents($path, "#!/usr/bin/env bash\ncat <<'EOF'\n{$output}\nEOF\n");
        chmod($path, 0o700);
        $this->scripts[] = $path;

        return $path;
    }

    public function test_it_returns_the_payload_it_signed_and_the_signature(): void
    {
        $signer = new GnupgOperationSigner($this->gpgPrinting("-----BEGIN PGP SIGNATURE-----\nx\n-----END PGP SIGNATURE-----"));

        $result = $signer->sign('plugins.remove', ['name' => 'MailPlugin'], 'cm4070', 1_800_000_000);

        self::assertNotNull($result);
        [$payload, $signature] = $result;
        self::assertStringContainsString('BEGIN PGP SIGNATURE', $signature);

        // The payload must be the canonical authorization, byte for byte — the verifier rebuilds
        // it from the call and compares, so anything else is rejected as a different call.
        $authorization = OperationAuthorization::fromCanonical($payload);
        self::assertSame('plugins.remove', $authorization?->operation);
        self::assertSame(['name' => 'MailPlugin'], $authorization?->arguments);
        self::assertSame('cm4070', $authorization?->host);
    }

    public function test_a_declined_signature_authorizes_nothing(): void
    {
        // gpg printing anything that is not a signature: the operator said no, the card was
        // removed, no key exists. All the same answer.
        $signer = new GnupgOperationSigner($this->gpgPrinting('gpg: signing failed: Operation cancelled'));

        self::assertNull($signer->sign('plugins.remove', ['name' => 'MailPlugin'], 'cm4070', 1_800_000_000));
    }

    public function test_a_missing_binary_authorizes_nothing(): void
    {
        $signer = new GnupgOperationSigner('/nonexistent/gpg');

        self::assertNull($signer->sign('plugins.remove', [], 'cm4070', 1_800_000_000));
    }

    /** A fake gpg that refuses to sign and answers `--list-secret-keys` with the given colon listing. */
    private function gpgListing(string $listing): string
    {
        $path = sys_get_temp_dir() . '/fake-list-' . bin2hex(random_bytes(6));
        file_put_contents($path, "#!/usr/bin/env bash\ncase \"$*\" in *--list-secret-keys*) cat <<'EOF'\n{$listing}\nEOF\n;; *) echo 'gpg: signing failed: No secret key' >&2; exit 2;; esac\n");
        chmod($path, 0o700);
        $this->scripts[] = $path;

        return $path;
    }

    public function test_an_empty_keyring_says_there_is_no_key_and_how_to_make_one(): void
    {
        $signer = new GnupgOperationSigner($this->gpgListing(''));
        self::assertNull($signer->sign('plugins.remove', [], 'cm4070', 1_800_000_000));

        $why = $signer->whyNotSigned();

        self::assertSame(SigningFailure::NO_KEY, $why?->kind);
        self::assertStringContainsString('No key that can sign is in the keyring this terminal reads', $why->reason);
        self::assertContains('  ' . GnupgOperationSigner::CREATE_A_KEY, $why->remedy);
    }

    public function test_only_expired_or_revoked_keys_count_as_no_key(): void
    {
        $signer = new GnupgOperationSigner($this->gpgListing(
            "sec:e:255:22:AAAA:1:2::u:::scSC:::+:::ed25519:::0:\nuid:e::::1::X::old <old@x>::::::::::0:\n"
            . "sec:r:255:22:BBBB:1:::u:::scSC:::+:::ed25519:::0:\nuid:r::::1::Y::gone <gone@x>::::::::::0:",
        ));

        self::assertSame(SigningFailure::NO_KEY, $signer->whyNotSigned()?->kind);
    }

    public function test_a_key_that_can_sign_means_the_prompt_was_not_answered(): void
    {
        // The mutation control of the scan: the same listing with the key valid flips NO_KEY to NOT_GIVEN.
        $signer = new GnupgOperationSigner($this->gpgListing(
            "sec:u:255:22:AAAA:1:::u:::scSC:::+:::ed25519:::0:\nuid:u::::1::X::Rod <rod@x>::::::::::0:",
        ));

        $why = $signer->whyNotSigned();

        self::assertSame(SigningFailure::NOT_GIVEN, $why?->kind);
        self::assertStringStartsWith('Rod <rod@x> can sign', $why->reason);
    }

    public function test_a_key_that_cannot_sign_is_no_key(): void
    {
        // An encryption-only key (capabilities without S) cannot produce what --sign needs.
        $signer = new GnupgOperationSigner($this->gpgListing(
            "sec:u:255:22:AAAA:1:::u:::eE:::+:::cv25519:::0:\nuid:u::::1::X::Enc <enc@x>::::::::::0:",
        ));

        self::assertSame(SigningFailure::NO_KEY, $signer->whyNotSigned()?->kind);
    }

    public function test_a_missing_binary_says_gpg_cannot_be_run(): void
    {
        $signer = new GnupgOperationSigner('/nonexistent/gpg');

        $why = $signer->whyNotSigned();

        self::assertSame(SigningFailure::NO_GPG, $why?->kind);
        self::assertStringContainsString('/nonexistent/gpg', $why->reason);
    }

    public function test_a_named_key_is_named_in_the_refusal(): void
    {
        $signer = new GnupgOperationSigner($this->gpgListing(''), 'C2B9AAAA');

        self::assertStringContainsString('No key «C2B9AAAA» that can sign', (string) $signer->whyNotSigned()?->reason);
    }

    public function test_the_keyring_named_is_the_one_gnupghome_points_at(): void
    {
        $before = getenv('GNUPGHOME');
        putenv('GNUPGHOME=/tmp/resident-keyring');
        try {
            $why = (new GnupgOperationSigner($this->gpgListing('')))->whyNotSigned();
        } finally {
            putenv($before === false ? 'GNUPGHOME' : 'GNUPGHOME=' . $before);
        }

        self::assertStringContainsString('(/tmp/resident-keyring)', (string) $why?->reason);
    }

    public function test_a_real_gpg_tells_an_empty_keyring_from_a_prompt_it_could_not_show(): void
    {
        // The instrument against the real binary, not a script that answers what the test wrote.
        if (trim((string) shell_exec('command -v gpg 2>/dev/null')) === '') {
            self::markTestSkipped('gpg is not installed on this machine');
        }
        $home = sys_get_temp_dir() . '/milpa-gpg-' . bin2hex(random_bytes(6));
        mkdir($home, 0o700);
        $before = getenv('GNUPGHOME');
        putenv('GNUPGHOME=' . $home);
        try {
            $signer = new GnupgOperationSigner();
            self::assertNull($signer->sign('plugins.remove', [], 'cm4070', 1_800_000_000));
            self::assertSame(SigningFailure::NO_KEY, $signer->whyNotSigned()?->kind);

            // A key with a passphrase gpg is forbidden to ask for: present, and still no signature.
            shell_exec("gpg --batch --passphrase lab --pinentry-mode loopback --quick-gen-key 'lab <lab@localhost>' ed25519 sign never 2>/dev/null");
            file_put_contents($home . '/gpg.conf', "pinentry-mode error\n");
            self::assertNull($signer->sign('plugins.remove', [], 'cm4070', 1_800_000_000));
            self::assertSame(SigningFailure::NOT_GIVEN, $signer->whyNotSigned()?->kind);
        } finally {
            shell_exec('gpgconf --homedir ' . escapeshellarg($home) . ' --kill gpg-agent 2>/dev/null');
            shell_exec('rm -rf ' . escapeshellarg($home));
            putenv($before === false ? 'GNUPGHOME' : 'GNUPGHOME=' . $before);
        }
    }

    public function test_every_authorization_gets_its_own_nonce(): void
    {
        // Two signatures for the identical call must not be interchangeable, or the single-use
        // ledger would reject the second legitimate one — and worse, the first would still work
        // twice.
        $signer = new GnupgOperationSigner($this->gpgPrinting("-----BEGIN PGP SIGNATURE-----\nx\n-----END PGP SIGNATURE-----"));

        $first = $signer->sign('plugins.remove', ['name' => 'MailPlugin'], 'cm4070', 1_800_000_000);
        $second = $signer->sign('plugins.remove', ['name' => 'MailPlugin'], 'cm4070', 1_800_000_000);

        self::assertNotSame(
            OperationAuthorization::fromCanonical((string) $first[0])?->nonce,
            OperationAuthorization::fromCanonical((string) $second[0])?->nonce,
        );
    }

    public function test_it_leaves_no_authorization_on_disk(): void
    {
        // The payload names an operation and its arguments — what someone was about to do.
        $before = (array) glob(sys_get_temp_dir() . '/milpa-authz-*');

        $signer = new GnupgOperationSigner($this->gpgPrinting('not a signature'));
        $signer->sign('plugins.remove', ['name' => 'MailPlugin'], 'cm4070', 1_800_000_000);

        self::assertSame(\count($before), \count((array) glob(sys_get_temp_dir() . '/milpa-authz-*')));
    }
}

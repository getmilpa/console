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

use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Operation;
use Milpa\Console\CliRunner;
use Milpa\Console\OperationPermissionPolicy;
use Milpa\Console\SequenceReceipts;
use Milpa\Console\Testing\SignsOperations;
use Milpa\Container\DIContainer;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Identity\GrantedAuthorization;
use Milpa\ToolRuntime\Identity\NonceLedger;
use Milpa\ToolRuntime\Identity\OperationAuthorization;
use Milpa\ToolRuntime\Identity\OperationAuthorizer;
use Milpa\ToolRuntime\Identity\SignatureVerifier;
use Milpa\ToolRuntime\Identity\VerifiedSigner;
use PHPUnit\Framework\TestCase;

/**
 * The CLI judges an operation's `permission` for a FINITE caller, the way it judges its scopes.
 *
 * Up to 0.22.1 `CliRunner` built the ToolDefinition it judges from `scopes` only: for a caller whose
 * authority is bounded — an enrolled seat that signs a leg, a scoped token — a scopes-typed operation
 * was judged and a permission-typed one ran unjudged. The local shell holds the terminal's wildcard
 * and is not asked; that is unchanged.
 */
final class CliPermissionTest extends TestCase
{
    use SignsOperations;

    private const SEAT = 'AAAA1111AAAA1111AAAA1111AAAA1111AAAA1111';

    private int $ran = 0;

    private function permissionedRead(): Operation
    {
        return new Operation(
            name: 'grades.read',
            description: 'Read the grades of a group',
            handler: function (): array {
                ++$this->ran;

                return ['grades' => [10, 9]];
            },
            permission: 'school.grades:read',
            effects: EffectProfile::readOnly(),
        );
    }

    private function container(?OperationPermissionPolicy $judge = null): DIContainer
    {
        $container = new DIContainer();
        if ($judge !== null) {
            $container->registerService(OperationPermissionPolicy::class, $judge);
        }

        return $container;
    }

    /** A seat whose signature the house recognizes with a bounded list of scopes (greenhouse 0499). */
    private function seatRunner(): CliRunner
    {
        return new CliRunner(
            signer: $this->alwaysSigns(),
            authorizer: $this->acceptingAuthorizer(),
            signerAuthority: static fn (VerifiedSigner $s): ToolContext => new ToolContext(principal: 'key:' . $s->fingerprint, channel: 'cli', scopes: ['agent:run', 'agent:read', 'plugins:read']),
            callerAuthority: ToolContext::cli(),
        );
    }

    /**
     * @param list<string> $argv
     *
     * @return array{int, string}
     */
    private function invoke(CliRunner $runner, Operation $op, array $argv, DIContainer $container): array
    {
        $lines = [];
        $exit = $runner->run($op, $argv, $container, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        return [$exit, implode("\n", $lines)];
    }

    public function test_a_signed_seat_without_a_judge_is_refused(): void
    {
        [$exit, $out] = $this->invoke($this->seatRunner(), $this->permissionedRead(), ['--sign'], $this->container());

        self::assertSame(1, $exit, $out);
        self::assertSame(0, $this->ran, 'a permission nobody can judge is not granted to a finite caller');
        self::assertStringContainsString('school.grades:read', $out);
        self::assertStringContainsString(OperationPermissionPolicy::class, $out);
        self::assertStringNotContainsString('authorized by', $out, 'a refused caller leaves no grant');
    }

    public function test_a_signed_seat_the_judge_denies_is_refused_and_leaves_no_grant(): void
    {
        $container = $this->container(new JudgeDouble(allow: false));

        [$exit, $out] = $this->invoke($this->seatRunner(), $this->permissionedRead(), ['--sign'], $container);

        self::assertSame(1, $exit, $out);
        self::assertSame(0, $this->ran);
        self::assertStringContainsString("Permission 'school.grades:read' is required.", $out);
        self::assertFalse($container->has(GrantedAuthorization::class));
    }

    public function test_a_signed_seat_the_judge_admits_runs(): void
    {
        $judge = new JudgeDouble(allow: true);

        [$exit, $out] = $this->invoke($this->seatRunner(), $this->permissionedRead(), ['--sign'], $this->container($judge));

        self::assertSame(0, $exit, $out);
        self::assertSame(1, $this->ran);
        self::assertNotEmpty($judge->asked);
        self::assertSame('key:BE7554E982E2CA5A0213B6067D72DEBDA1D36D34', $judge->asked[0]['caller']->principal);
    }

    public function test_a_finite_caller_authority_is_judged_without_signing(): void
    {
        $token = new CliRunner(callerAuthority: new ToolContext(principal: 'token:reader', channel: 'cli', scopes: ['notes:read']));

        [$refused, $out] = $this->invoke($token, $this->permissionedRead(), [], $this->container());
        self::assertSame(1, $refused, $out);
        self::assertSame(0, $this->ran);

        [$admitted, $out] = $this->invoke($token, $this->permissionedRead(), [], $this->container(new JudgeDouble(allow: true)));
        self::assertSame(0, $admitted, $out);
        self::assertSame(1, $this->ran);
    }

    /** @return iterable<string, array{?ToolContext}> */
    public static function localShells(): iterable
    {
        yield 'no caller authority' => [null];
        yield 'ToolContext::cli()' => [ToolContext::cli()];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('localShells')]
    public function test_the_local_shell_is_unchanged(?ToolContext $shell): void
    {
        $judge = new JudgeDouble(allow: false);
        $runner = new CliRunner(callerAuthority: $shell);

        [$withoutJudge, $out] = $this->invoke($runner, $this->permissionedRead(), [], $this->container());
        self::assertSame(0, $withoutJudge, $out);
        [$withJudge, $out] = $this->invoke($runner, $this->permissionedRead(), [], $this->container($judge));
        self::assertSame(0, $withJudge, $out);

        self::assertSame(2, $this->ran);
        self::assertSame([], $judge->asked, 'the terminal wildcard is not asked for a permission');
    }

    public function test_a_signer_the_house_recognizes_with_the_wildcard_is_unchanged(): void
    {
        $owner = new CliRunner(
            signer: $this->alwaysSigns(),
            authorizer: $this->acceptingAuthorizer(),
            signerAuthority: static fn (VerifiedSigner $s): ToolContext => new ToolContext(principal: 'key:' . $s->fingerprint, channel: 'cli', scopes: ['*']),
            callerAuthority: ToolContext::cli(),
        );

        [$exit, $out] = $this->invoke($owner, $this->permissionedRead(), ['--sign'], $this->container());

        self::assertSame(0, $exit, $out);
        self::assertSame(1, $this->ran);
    }

    public function test_a_scopes_typed_operation_never_asks_the_judge(): void
    {
        $judge = new JudgeDouble(allow: false);
        $op = new Operation(name: 'notes.read', description: 'Read notes', handler: function (): string {
            ++$this->ran;

            return 'notes';
        }, scopes: ['agent:read'], effects: EffectProfile::readOnly());

        [$exit, $out] = $this->invoke($this->seatRunner(), $op, ['--sign'], $this->container($judge));

        self::assertSame(0, $exit, $out);
        self::assertSame(1, $this->ran);
        self::assertSame([], $judge->asked);
    }

    /**
     * A leg that continues a signed sequence runs as the seat that opened it (greenhouse 0500); the
     * permission is judged again for that leg, against today.
     */
    public function test_a_leg_that_cites_a_seat_receipt_is_judged_again(): void
    {
        $judge = new JudgeDouble(allow: true);
        $container = $this->container($judge);
        $book = new ReceiptBookDouble();
        $op = new Operation(
            name: 'grades.follow',
            description: 'Follow a group session',
            handler: function (): array {
                ++$this->ran;

                return ['ok' => true];
            },
            inputSchema: ['type' => 'object', 'properties' => ['session' => ['type' => 'string']]],
            permission: 'school.grades:read',
            effects: EffectProfile::readOnly(),
            continues: static fn (array $a): ?string => \is_string($a['session'] ?? null) ? $a['session'] : null,
        );
        $verifier = new class () implements SignatureVerifier {
            public function verify(string $payload, string $signature): ?VerifiedSigner
            {
                return $signature === 'sig:' . hash('sha256', $payload) ? new VerifiedSigner(CliPermissionTest::seat(), 'Seat <seat@lab>') : null;
            }
        };
        $runner = new CliRunner(
            signer: new class () implements \Milpa\Console\OperationSigner {
                public function sign(string $operation, array $arguments, string $host, int $now): ?array
                {
                    $payload = (new OperationAuthorization($operation, $arguments, $host, gmdate('c', $now), bin2hex(random_bytes(8))))->canonical();

                    return [$payload, 'sig:' . hash('sha256', $payload)];
                }
            },
            authorizer: new OperationAuthorizer($verifier, new class () implements NonceLedger {
                public function spend(string $nonce, int $ttlSeconds, int $now): bool
                {
                    return true;
                }
            }),
            signerAuthority: static fn (VerifiedSigner $s): ToolContext => new ToolContext(principal: 'key:' . $s->fingerprint, channel: 'cli', scopes: ['agent:run']),
            callerAuthority: ToolContext::cli(),
            verifier: $verifier,
            receipts: $book,
        );

        [$opened, $out] = $this->invoke($runner, $op, ['--session=s1', '--sign'], $container);
        self::assertSame(0, $opened, $out);
        self::assertArrayHasKey('s1', $book->kept);

        $judge->allow = false;
        [$cited, $out] = $this->invoke($runner, $op, ['--session=s1'], $container);

        self::assertSame(1, $cited, $out);
        self::assertSame(1, $this->ran, 'the cited leg did not run');
        self::assertSame([], $book->citations, 'a refused leg cites nothing');
    }

    /** The seat fingerprint the verifier above answers with. */
    public static function seat(): string
    {
        return self::SEAT;
    }
}

/** A sequence book kept in memory, the way the host's session store keeps it. */
final class ReceiptBookDouble implements SequenceReceipts
{
    /** @var array<string, array<string, mixed>> */
    public array $kept = [];

    /** @var list<array{string, string, string}> */
    public array $citations = [];

    public function record(string $sequence, string $operation, GrantedAuthorization $granted, mixed $result): void
    {
        $this->kept[$sequence] = [
            'operation' => $operation,
            'payload' => $granted->payload,
            'signature' => $granted->signature,
            'fingerprint' => $granted->signer->fingerprint,
            'uid' => $granted->signer->uid,
        ];
    }

    public function standing(string $sequence): ?array
    {
        /** @var array{operation: string, payload: string, signature: string, fingerprint: string, uid?: ?string}|null */
        return $this->kept[$sequence] ?? null;
    }

    public function cited(string $sequence, string $operation, string $receiptId): void
    {
        $this->citations[] = [$sequence, $operation, $receiptId];
    }

    public function settled(string $sequence, string $operation, mixed $result): void
    {
    }
}

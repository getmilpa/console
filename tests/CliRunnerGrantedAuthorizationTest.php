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

use Milpa\Console\CliRunner;
use Milpa\Console\OperationSigner;
use Milpa\Console\Testing\SignsOperations;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\ToolRuntime\Identity\GrantedAuthorization;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The verdict travels into the run, instead of dying at the banner.
 *
 * Until now a granted signature printed "authorized by ..." and dropped the signer, so a handler
 * that wanted to persist the grant as an assertion (greenhouse decisions/0056) had nothing to
 * persist. These tests pin the fix from the handler's side of the seam: after the gate says yes,
 * the container holds a {@see GrantedAuthorization} carrying the EXACT signed bytes — and after
 * any refusal, it holds nothing, because a record of a grant that was never granted would be
 * worse than the dropped one.
 */
#[CoversClass(CliRunner::class)]
final class CliRunnerGrantedAuthorizationTest extends TestCase
{
    use SignsOperations;

    /** @var list<string> */
    private array $out = [];

    /** @var array<string, object> */
    private array $registered = [];

    /** The exact pair the signer produced, captured at the only place it exists untouched. */
    private ?string $signedPayload = null;

    private ?string $signedSignature = null;

    /**
     * A container that remembers what the gate registers, which is the whole subject here.
     */
    private function recordingContainer(): DIContainerInterface
    {
        $record = function (string $id, object $instance): void {
            $this->registered[$id] = $instance;
        };
        $lookup = fn (string $id): ?object => $this->registered[$id] ?? null;

        return new class ($record, $lookup) implements DIContainerInterface {
            public function __construct(
                private readonly \Closure $record,
                private readonly \Closure $lookup,
            ) {
            }

            public function registerService(string $id, string|object $classOrInstance): void
            {
                if (\is_object($classOrInstance)) {
                    ($this->record)($id, $classOrInstance);
                }
            }

            public function get(string $id): mixed
            {
                return ($this->lookup)($id);
            }

            public function has(string $id): bool
            {
                return ($this->lookup)($id) !== null;
            }

            public function tryGet(string $id): mixed
            {
                return ($this->lookup)($id);
            }

            public function resolve(string $className, bool $singleton = true): mixed
            {
                throw new \RuntimeException('no autowiring');
            }

            public function compileContainer(): void
            {
            }

            public function getContainer(): \Psr\Container\ContainerInterface
            {
                throw new \RuntimeException('not needed');
            }
        };
    }

    /**
     * The trait's always-answering key, wrapped so the test can see the exact bytes it emitted.
     *
     * Captured at the seam and not rebuilt: the assertion below is byte-equality against what was
     * signed, and rebuilding the payload here would make the test compare two copies of the same
     * mistake.
     */
    private function recordingSigner(): OperationSigner
    {
        $capture = function (string $payload, string $signature): void {
            $this->signedPayload = $payload;
            $this->signedSignature = $signature;
        };

        return new class ($this->alwaysSigns(), $capture) implements OperationSigner {
            public function __construct(
                private readonly OperationSigner $inner,
                private readonly \Closure $capture,
            ) {
            }

            public function sign(string $operation, array $arguments, string $host, int $now): ?array
            {
                $signed = $this->inner->sign($operation, $arguments, $host, $now);
                if ($signed !== null) {
                    ($this->capture)($signed[0], $signed[1]);
                }

                return $signed;
            }
        };
    }

    private function operation(): Operation
    {
        return new Operation(
            name: 'plugins.remove',
            description: 'Removes a plugin',
            handler: fn (array $input): int => 0,
            inputSchema: null,
            mutating: true,
            requiresConfirmation: true,
            effects: new EffectProfile(
                mutation: Mutation::Persistent,
                externality: Externality::None,
                reversibility: Reversibility::Guaranteed,
                authority: Authority::Read,
                subject: Subject::Data,
                rollbackContract: 'test probe: nothing leaves this process',
            ),
        );
    }

    private function project(CliRunner $runner, array $argv): int
    {
        $this->out = [];

        return $runner->run($this->operation(), $argv, $this->recordingContainer(), function (string $line): void {
            $this->out[] = $line;
        });
    }

    public function test_a_granted_verdict_registers_the_grant_with_the_exact_signed_bytes(): void
    {
        $runner = new CliRunner(signer: $this->recordingSigner(), authorizer: $this->acceptingAuthorizer());

        $exit = $this->project($runner, ['--name=MailPlugin', '--sign']);

        self::assertSame(0, $exit);

        $granted = $this->registered[GrantedAuthorization::class] ?? null;
        self::assertInstanceOf(GrantedAuthorization::class, $granted);

        // Byte-exact, not equivalent: a consumer re-verifies the signature over this payload
        // (greenhouse evidence/0254), and re-verification of a paraphrase proves nothing.
        self::assertSame($this->signedPayload, $granted->payload);
        self::assertSame($this->signedSignature, $granted->signature);

        // The signer is the verdict's — the key the accepting verifier established, not a retelling.
        self::assertSame('BE7554E982E2CA5A0213B6067D72DEBDA1D36D34', $granted->signer->fingerprint);

        // The parsed claim rides along and names this very call. A schema-less operation signs its
        // raw bag, and the bag carries the `--sign` token itself — pinned as-is, because the claim
        // here is "the arguments that were signed", not "the arguments minus what surprised us".
        self::assertSame('plugins.remove', $granted->authorization->operation);
        self::assertSame(['name' => 'MailPlugin', 'sign' => '1'], $granted->authorization->arguments);
    }

    public function test_without_sign_nothing_is_registered(): void
    {
        $exit = $this->project(new CliRunner(), ['--name=MailPlugin']);

        self::assertSame(1, $exit);
        self::assertSame([], $this->registered, 'No grant, no record of one.');
    }

    public function test_a_declined_signature_registers_nothing(): void
    {
        $declines = new class () implements OperationSigner {
            public function sign(string $operation, array $arguments, string $host, int $now): ?array
            {
                return null; // declined at the card, or no key
            }
        };

        $exit = $this->project(new CliRunner(signer: $declines), ['--name=MailPlugin', '--sign']);

        self::assertSame(1, $exit);
        self::assertSame([], $this->registered);
    }

    public function test_a_denied_verdict_registers_nothing(): void
    {
        // Signed for real, refused for real: the verifier inside this authorizer answers null, so
        // the gate reaches the verdict and the verdict is no. The refusal must leave no residue a
        // handler could mistake for consent.
        $runner = new CliRunner(signer: $this->recordingSigner(), authorizer: $this->rejectingAuthorizer());

        $exit = $this->project($runner, ['--name=MailPlugin', '--sign']);

        self::assertSame(1, $exit);
        self::assertSame([], $this->registered);
    }

    /**
     * The real authorizer with a verifier that never believes, for the denied branch.
     */
    private function rejectingAuthorizer(): \Milpa\ToolRuntime\Identity\OperationAuthorizer
    {
        $verifier = new class () implements \Milpa\ToolRuntime\Identity\SignatureVerifier {
            public function verify(string $payload, string $signature): ?\Milpa\ToolRuntime\Identity\VerifiedSigner
            {
                return null;
            }
        };

        $ledger = new class () implements \Milpa\ToolRuntime\Identity\NonceLedger {
            public function spend(string $nonce, int $ttlSeconds, int $now): bool
            {
                return true;
            }
        };

        return new \Milpa\ToolRuntime\Identity\OperationAuthorizer($verifier, $ledger, 120);
    }
}

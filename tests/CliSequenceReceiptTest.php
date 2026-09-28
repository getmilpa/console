<?php

/**
 * (c) Rodrigo Vicente - TeamX Agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\Console\Tests;

use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\InvocationContext;
use Milpa\Command\Operation;
use Milpa\Console\CliRunner;
use Milpa\Console\SequenceReceipts;
use Milpa\Console\Testing\SignsOperations;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Identity\GrantedAuthorization;
use Milpa\ToolRuntime\Identity\NonceLedger;
use Milpa\ToolRuntime\Identity\OperationAuthorization;
use Milpa\ToolRuntime\Identity\OperationAuthorizer;
use Milpa\ToolRuntime\Identity\SignatureVerifier;
use Milpa\ToolRuntime\Identity\VerifiedSigner;
use PHPUnit\Framework\TestCase;

/**
 * One signature per sequence: the first call signs, the calls after it CITE the receipt it left
 * (greenhouse decisions/0458, 0500) — re-verified, bound to the sequence, judged against today.
 */
final class CliSequenceReceiptTest extends TestCase
{
    use SignsOperations;

    private const SEAT = 'AAAA1111AAAA1111AAAA1111AAAA1111AAAA1111';

    /** @var list<array{0: ?InvocationContext, 1: ?ToolContext, 2: array<string, mixed>}> */
    private array $calls = [];

    /** A verifier that verifies only what it signed: tampering with the bytes is visible. */
    private function verifier(string $fingerprint = self::SEAT): SignatureVerifier
    {
        return new class ($fingerprint) implements SignatureVerifier {
            public function __construct(private string $fingerprint)
            {
            }

            public function verify(string $payload, string $signature): ?VerifiedSigner
            {
                return $signature === 'sig:' . hash('sha256', $payload)
                    ? new VerifiedSigner($this->fingerprint, 'Resident <resident@lab>')
                    : null;
            }
        };
    }

    /** A signer the verifier above accepts, so the signed call and the cited one share one judge. */
    private function signer(): \Milpa\Console\OperationSigner
    {
        return new class () implements \Milpa\Console\OperationSigner {
            public function sign(string $operation, array $arguments, string $host, int $now): ?array
            {
                $payload = (new OperationAuthorization($operation, $arguments, $host, gmdate('c', $now), bin2hex(random_bytes(8))))->canonical();

                return [$payload, 'sig:' . hash('sha256', $payload)];
            }
        };
    }

    private function authorizer(): OperationAuthorizer
    {
        return new OperationAuthorizer($this->verifier(), new class () implements NonceLedger {
            public function spend(string $nonce, int $ttlSeconds, int $now): bool
            {
                return true;
            }
        });
    }

    /** @return SequenceReceipts&object{kept: array<string, array<string, mixed>>, citations: list<array{string, string, string}>} */
    private function book(): SequenceReceipts
    {
        return new class () implements SequenceReceipts {
            /** @var array<string, array<string, mixed>> */
            public array $kept = [];
            /** @var list<array{string, string, string}> */
            public array $citations = [];
            /** @var list<array{string, string, mixed}> */
            public array $settled = [];

            public function record(string $sequence, string $operation, GrantedAuthorization $granted, mixed $result): void
            {
                if (($result['done'] ?? false) === true) {
                    unset($this->kept[$sequence]);

                    return;
                }
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
                $this->settled[] = [$sequence, $operation, $result];
                if (($result['done'] ?? false) === true) {
                    unset($this->kept[$sequence]);
                }
            }
        };
    }

    /** An operation that continues its `session`, like `agent` does. */
    private function driver(bool $demandsConsent = false): Operation
    {
        return new Operation(
            name: 'agent',
            description: 'Drive a session',
            handler: function (array $input, ?InvocationContext $context = null, ?ToolContext $authority = null): array {
                $this->calls[] = [$context, $authority, $input];

                return ['ok' => true, 'done' => ($input['prompt'] ?? null) === 'finish'];
            },
            inputSchema: ['type' => 'object', 'properties' => ['session' => ['type' => 'string'], 'prompt' => ['type' => 'string']]],
            mutating: true,
            requiresConfirmation: $demandsConsent,
            scopes: ['agent:run'],
            // Declared like `agent`: it writes as the user and changes data, so S2 does not demand a
            // signature — the signature is who drives it, not a consent.
            effects: new EffectProfile(Mutation::Persistent, Externality::ThirdParty, Reversibility::Irreversible, Authority::WriteAsUser, subject: Subject::Data),
            continues: static fn (array $a): ?string => \is_string($a['session'] ?? null) ? $a['session'] : null,
        );
    }

    /** @param list<string> $scopes */
    private function runner(SequenceReceipts $book, array $scopes = ['agent:run'], ?SignatureVerifier $verifier = null): CliRunner
    {
        return new CliRunner(
            signer: $this->signer(),
            authorizer: $this->authorizer(),
            signerAuthority: static fn (VerifiedSigner $s): ToolContext => new ToolContext(principal: 'key:' . $s->fingerprint, channel: 'cli', scopes: $scopes),
            callerAuthority: ToolContext::cli(),
            verifier: $verifier ?? $this->verifier(),
            receipts: $book,
        );
    }

    /**
     * @param list<string> $argv
     *
     * @return array{int, string}
     */
    private function invoke(CliRunner $runner, Operation $op, array $argv): array
    {
        $lines = [];
        $exit = $runner->run($op, $argv, $this->createMock(DIContainerInterface::class), static function (string $l) use (&$lines): void {
            $lines[] = $l;
        });

        return [$exit, implode("\n", $lines)];
    }

    /** Keep a receipt the way a first signed call leaves it, with optional surgery on it. */
    private function openSequence(SequenceReceipts $book, string $session = 's1', string $operation = 'agent'): void
    {
        [$exit] = $this->invoke($this->runner($book), $this->driver(), ["--session={$session}", '--prompt=build it', '--sign']);
        self::assertSame(0, $exit);
        if ($operation !== 'agent') {
            $book->kept[$session]['operation'] = $operation;
        }
        $this->calls = [];
    }

    public function testTheSignedCallThatOpensASequenceLeavesItsReceipt(): void
    {
        $book = $this->book();
        $this->openSequence($book);

        self::assertArrayHasKey('s1', $book->kept);
        self::assertSame(self::SEAT, $book->kept['s1']['fingerprint']);
    }

    public function testAResumeWithoutSignatureRunsUnderTheSeatThatOpenedIt(): void
    {
        $book = $this->book();
        $this->openSequence($book);
        $opening = 'sha256:' . hash('sha256', $book->kept['s1']['payload']);

        [$exit, $out] = $this->invoke($this->runner($book), $this->driver(), ['--session=s1', '--prompt=continue']);

        self::assertSame(0, $exit, $out);
        self::assertCount(1, $this->calls);
        [$context, $authority] = $this->calls[0];
        self::assertSame('key:' . self::SEAT, $context?->actor);
        self::assertTrue($context?->verified);
        self::assertSame($opening, $context?->authorizationId, 'the leg runs under the id of the signature it cites');
        self::assertSame('key:' . self::SEAT, $authority?->principal);
        self::assertSame(['agent:run'], $authority?->scopes, 'the seat, not the terminal wildcard');
        self::assertSame([['s1', 'agent', $opening]], $book->citations);
        self::assertStringContainsString('continuing under the signature of', $out);
    }

    public function testAConsentTheOperationDemandsIsSatisfiedByTheCitedCeremony(): void
    {
        $book = $this->book();
        $op = $this->driver(demandsConsent: true);
        [$exit] = $this->invoke($this->runner($book), $op, ['--session=r1', '--sign']);
        self::assertSame(0, $exit);

        [$exit, $out] = $this->invoke($this->runner($book), $op, ['--session=r1']);

        self::assertSame(0, $exit, $out);
        self::assertCount(2, $this->calls);
    }

    public function testASequenceOpenedWithoutASignatureStillAsksForOne(): void
    {
        $book = $this->book();
        [$exit, $out] = $this->invoke($this->runner($book), $this->driver(demandsConsent: true), ['--session=r1']);

        self::assertSame(1, $exit);
        self::assertSame([], $this->calls);
        self::assertStringContainsString('Re-run with --sign', $out);
        self::assertSame([], $book->kept, 'an unsigned call leaves no receipt to inherit');
    }

    public function testAnUnsignedDriverWithNoReceiptKeepsTheLocalDefault(): void
    {
        [$exit] = $this->invoke($this->runner($this->book()), $this->driver(), ['--session=s1', '--prompt=hi']);

        self::assertSame(0, $exit);
        self::assertNull($this->calls[0][0]);
        self::assertSame('local-shell', $this->calls[0][1]?->principal, 'decisions/0311: the terminal may omit a signature');
    }

    public function testAReceiptLiftedFromAnotherSequenceIsRefused(): void
    {
        $book = $this->book();
        $this->openSequence($book, 'other');
        $book->kept['s1'] = $book->kept['other'];

        [$exit, $out] = $this->invoke($this->runner($book), $this->driver(), ['--session=s1', '--prompt=continue']);

        self::assertSame(1, $exit);
        self::assertSame([], $this->calls);
        self::assertStringContainsString('different sequence', $out);
        self::assertSame([], $book->citations);
    }

    public function testATamperedReceiptIsRefused(): void
    {
        $book = $this->book();
        $this->openSequence($book);
        $book->kept['s1']['payload'] = str_replace('build it', 'rm -rf it', $book->kept['s1']['payload']);

        [$exit, $out] = $this->invoke($this->runner($book), $this->driver(), ['--session=s1', '--prompt=continue']);

        self::assertSame(1, $exit);
        self::assertSame([], $this->calls);
        self::assertStringContainsString('does not verify', $out);
    }

    public function testAnExpiredOrRevokedKeyThatNoLongerSignsIsRefused(): void
    {
        $book = $this->book();
        $this->openSequence($book);
        $gpgSaysNo = new class () implements SignatureVerifier {
            public function verify(string $payload, string $signature): ?VerifiedSigner
            {
                return null;
            }
        };

        [$exit] = $this->invoke($this->runner($book, verifier: $gpgSaysNo), $this->driver(), ['--session=s1', '--prompt=continue']);

        self::assertSame(1, $exit);
        self::assertSame([], $this->calls);
    }

    public function testAStoredFingerprintThatIsNotTheSignerIsRefused(): void
    {
        $book = $this->book();
        $this->openSequence($book);
        $book->kept['s1']['fingerprint'] = 'BBBB2222BBBB2222BBBB2222BBBB2222BBBB2222';

        [$exit, $out] = $this->invoke($this->runner($book), $this->driver(), ['--session=s1', '--prompt=continue']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('not the key that signed it', $out);
    }

    public function testAReceiptSignedForAnotherOperationIsRefused(): void
    {
        $book = $this->book();
        $this->openSequence($book);
        $other = new Operation(
            name: 'recipe:apply',
            description: 'x',
            handler: fn (): array => ['ok' => (bool) ($this->calls[] = [null, null, []])],
            inputSchema: ['type' => 'object', 'properties' => ['session' => ['type' => 'string']]],
            continues: static fn (array $a): ?string => \is_string($a['session'] ?? null) ? $a['session'] : null,
        );

        [$exit, $out] = $this->invoke($this->runner($book), $other, ['--session=s1']);

        self::assertSame(1, $exit);
        self::assertSame([], $this->calls);
        self::assertStringContainsString("signed 'agent'", $out);
    }

    public function testARevokedEnrollmentIsRefusedAndNeverWidensToTheTerminal(): void
    {
        $book = $this->book();
        $this->openSequence($book);

        [$exit, $out] = $this->invoke($this->runner($book, scopes: []), $this->driver(), ['--session=s1', '--prompt=continue']);

        self::assertSame(1, $exit);
        self::assertSame([], $this->calls, 'a revoked seat does not fall back to local-shell');
        self::assertStringContainsString('Missing required scope', $out);
        self::assertSame([], $book->citations);
    }

    public function testSigningAgainReplacesTheReceipt(): void
    {
        $book = $this->book();
        $this->openSequence($book);
        $first = $book->kept['s1']['payload'];

        [$exit] = $this->invoke($this->runner($book), $this->driver(), ['--session=s1', '--prompt=again', '--sign']);

        self::assertSame(0, $exit);
        self::assertNotSame($first, $book->kept['s1']['payload']);
        self::assertSame([], $book->citations, 'a signed call cites nothing');
    }

    public function testAnOperationThatContinuesNothingNeverConsultsTheBook(): void
    {
        $book = $this->book();
        $this->openSequence($book);
        $read = new Operation(name: 'read', description: 'x', handler: fn (): array => ['ok' => (bool) ($this->calls[] = [null, null, []])], effects: EffectProfile::readOnly());

        [$exit] = $this->invoke($this->runner($book), $read, ['--session=s1']);

        self::assertSame(0, $exit);
        self::assertSame([], $book->citations);
    }

    public function testWithoutABookTheDoorIsUnchanged(): void
    {
        $runner = new CliRunner(signer: $this->signer(), authorizer: $this->authorizer(), callerAuthority: ToolContext::cli(), verifier: $this->verifier());
        $op = $this->driver(demandsConsent: true);
        [$exit] = $this->invoke($runner, $op, ['--session=r1', '--sign']);
        self::assertSame(0, $exit);

        [$exit, $out] = $this->invoke($runner, $op, ['--session=r1']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('Re-run with --sign', $out);
    }

    public function testABookThatCannotKeepTheReceiptFailsClosedAndSaysSo(): void
    {
        $book = new class () implements SequenceReceipts {
            public function record(string $sequence, string $operation, GrantedAuthorization $granted, mixed $result): void
            {
                throw new \RuntimeException('store is read-only');
            }

            public function settled(string $sequence, string $operation, mixed $result): void
            {
            }

            public function standing(string $sequence): ?array
            {
                return null;
            }

            public function cited(string $sequence, string $operation, string $receiptId): void
            {
            }
        };

        [$exit, $out] = $this->invoke($this->runner($book), $this->driver(), ['--session=s1', '--sign']);

        self::assertSame(0, $exit, 'the call itself ran');
        self::assertStringContainsString('the next call will ask for --sign', $out);
    }

    public function testTheBookHearsHowACitedLegEndedAndTheEndedSequenceSignsAgain(): void
    {
        $book = $this->book();
        $this->openSequence($book);

        [$exit] = $this->invoke($this->runner($book), $this->driver(), ['--session=s1', '--prompt=finish']);
        self::assertSame(0, $exit);
        self::assertSame([['s1', 'agent', ['ok' => true, 'done' => true]]], $book->settled);

        [$exit, $out] = $this->invoke($this->runner($book), $this->driver(demandsConsent: true), ['--session=s1']);
        self::assertSame(1, $exit, 'a sequence that ended leaves nothing standing to cite');
        self::assertStringContainsString('Re-run with --sign', $out);
    }

    public function testASignedCallThatEndsItsSequenceInOneGoLeavesNothingStanding(): void
    {
        $book = $this->book();

        [$exit] = $this->invoke($this->runner($book), $this->driver(), ['--session=s1', '--prompt=finish', '--sign']);

        self::assertSame(0, $exit);
        self::assertSame([], $book->kept, 'the book reads the result after the call, so no release can precede the receipt');
    }
}

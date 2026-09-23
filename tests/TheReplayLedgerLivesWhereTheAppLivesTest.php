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

use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Console\CliRunner;
use Milpa\Console\Identity\AuthorizationLedger;
use Milpa\Console\OperationSigner;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Plugin\Contracts\AppRoot;
use Milpa\ToolRuntime\Identity\OperationAuthorization;
use Milpa\ToolRuntime\Identity\SignatureVerifier;
use Milpa\ToolRuntime\Identity\VerifiedSigner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Replay protection is written where the APP lives, and an app that cannot say where that is
 * refuses to spend an authorization rather than defaulting somewhere disposable.
 *
 * The path used to be `dirname(__DIR__, 2) . '/storage/authorizations'` — the FILE's answer, which
 * resolves to `vendor/milpa/` in every installed app. Measured landing there on a real signed run
 * (greenhouse `evidence/0990`), so `rm -rf vendor && composer install` erased the record that stops
 * a still-fresh authorization from being presented twice.
 *
 * The suite reaches this at all because the verifier is injectable now: the branch that composes
 * the default authorizer is the branch that chooses the ledger, and with a real key as its only
 * entrance it had no test.
 *
 * @guards the replay ledger's location and the refusal when the app root is unknown
 *
 * @fires   on every `--sign` that does not carry an injected authorizer
 *
 * @refuses an app with no declared root — it cannot spend an authorization
 *
 * @subject-in milpa/console
 */
#[CoversClass(CliRunner::class)]
#[CoversClass(AuthorizationLedger::class)]
final class TheReplayLedgerLivesWhereTheAppLivesTest extends TestCase
{
    /** @var list<string> */
    private array $out = [];

    /** @var array<string, object> */
    private array $registered = [];

    private string $root = '';

    protected function setUp(): void
    {
        $this->out = [];
        $this->registered = [];
        $this->root = sys_get_temp_dir() . '/milpa-ledger-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            $this->removeTree($this->root);
        }
    }

    public function testTheLedgerLandsUnderTheAppRootAndNowhereInsideVendor(): void
    {
        $code = $this->runSigned($this->containerWithRoot(), 'a-fixed-nonce');

        self::assertSame(0, $code, implode("\n", $this->out));
        self::assertSame(
            [AuthorizationLedger::under(new AppRoot($this->root))],
            $this->ledgerDirectoriesUnder($this->root),
            'the spent nonce has to be recorded under the root the app declared',
        );
        self::assertCount(1, $this->spentNonces(), 'exactly one authorization was spent');

        // THE NEGATIVE CONTROL of the defect this closes: the file's own neighbourhood — where
        // `dirname(__DIR__, 2)` pointed — must hold nothing. Without this, a path that happened to
        // ALSO write under the app root would read as fixed.
        self::assertSame(
            [],
            glob(\dirname(__DIR__) . '/storage/authorizations/*') ?: [],
            'nothing may be written beside this package',
        );
    }

    public function testTheSameAuthorizationCannotBeSpentTwice(): void
    {
        // THE POSITIVE CONTROL that the ledger at that path is LIVE and not an empty directory
        // someone created: the second presentation of the same nonce has to be refused. A test that
        // only asserted a file exists would pass against a ledger that never reads itself back.
        $first = $this->runSigned($this->containerWithRoot(), 'the-same-nonce-twice');
        $this->out = [];
        $second = $this->runSigned($this->containerWithRoot(), 'the-same-nonce-twice');

        self::assertSame(0, $first);
        self::assertSame(1, $second, 'a spent authorization cannot be spent again');
        self::assertCount(1, $this->spentNonces(), 'the replay left no second record');
    }

    public function testAnAppThatDoesNotSayWhereItLivesCannotSpendAnAuthorization(): void
    {
        $code = $this->runSigned($this->containerWithoutRoot(), 'a-fixed-nonce');
        $said = implode("\n", $this->out);

        self::assertSame(1, $code);
        self::assertStringContainsString('does not say where it lives', $said);
        // It names the fix, because a refusal that does not is one the reader cannot act on.
        self::assertStringContainsString('AppRoot', $said);
        self::assertSame([], $this->spentNonces(), 'a refusal spends nothing');
    }

    /** The one operation: grave enough that the door demands the signature. */
    private function operation(): Operation
    {
        return new Operation(
            name: 'ledger:probe',
            effects: new EffectProfile(
                Mutation::Persistent,
                Externality::None,
                Reversibility::ManualRecovery,
                Authority::Privileged,
                subject: Subject::Executable,
            ),
            description: 'A grave operation, so consent is demanded',
            handler: static fn (array $input): array => ['ok' => true],
            inputSchema: ['type' => 'object', 'properties' => [], 'required' => []],
            mutating: true,
        );
    }

    /** Runs the operation with `--sign`, a key that always answers and a verifier that accepts. */
    private function runSigned(DIContainerInterface $container, string $nonce): int
    {
        $runner = new CliRunner(
            signer: $this->signerWithNonce($nonce),
            verifier: new class () implements SignatureVerifier {
                public function verify(string $payload, string $signature): ?VerifiedSigner
                {
                    return new VerifiedSigner('BE7554E982E2CA5A0213B6067D72DEBDA1D36D34', 'Test Operator <test@example.com>');
                }
            },
        );

        return $runner->run($this->operation(), ['ledger:probe', '--sign'], $container, function (string $line): void {
            $this->out[] = $line;
        });
    }

    /**
     * A key whose nonce is FIXED, which is what makes the replay observable.
     *
     * The shared `SignsOperations` trait randomises it — correct for its own subject, and useless
     * here: two runs would never collide and the second call would pass for the wrong reason.
     */
    private function signerWithNonce(string $nonce): OperationSigner
    {
        return new class ($nonce) implements OperationSigner {
            public function __construct(private readonly string $nonce)
            {
            }

            public function sign(string $operation, array $arguments, string $host, int $now): ?array
            {
                $authorization = new OperationAuthorization(
                    operation: $operation,
                    arguments: $arguments,
                    host: $host,
                    issuedAt: gmdate('c', $now),
                    nonce: $this->nonce,
                );

                return [$authorization->canonical(), 'a signature the accepting verifier takes'];
            }
        };
    }

    private function containerWithRoot(): DIContainerInterface
    {
        $container = $this->container();
        $container->registerService(AppRoot::class, new AppRoot($this->root));

        return $container;
    }

    private function containerWithoutRoot(): DIContainerInterface
    {
        return $this->container();
    }

    private function container(): DIContainerInterface
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

    /** @return list<string> */
    private function spentNonces(): array
    {
        return array_values(glob(AuthorizationLedger::under(new AppRoot($this->root)) . '/*') ?: []);
    }

    /**
     * Every directory under the app root that holds a spent nonce — so the assertion names WHERE,
     * not merely how many.
     *
     * @return list<string>
     */
    private function ledgerDirectoriesUnder(string $root): array
    {
        $found = [];
        $walk = static function (string $dir) use (&$walk, &$found): void {
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $dir . '/' . $entry;
                if (is_dir($path)) {
                    $walk($path);

                    continue;
                }
                $found[\dirname($path)] = true;
            }
        };
        $walk($root);

        return array_values(array_map('strval', array_keys($found)));
    }

    private function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

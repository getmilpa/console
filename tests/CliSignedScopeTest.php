<?php

/**
 * (c) Rodrigo Vicente - TeamX Agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\Console\Tests;

use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\InvocationContext;
use Milpa\Command\Operation;
use Milpa\Console\CliRunner;
use Milpa\Console\Testing\SignsOperations;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Identity\GrantedAuthorization;
use Milpa\ToolRuntime\Identity\VerifiedSigner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A verified signature must face scope judgment before either a grant or an effect reaches the handler. */
final class CliSignedScopeTest extends TestCase
{
    use SignsOperations;

    /** @return iterable<string, array{list<string>, bool}> */
    public static function scopes(): iterable
    {
        yield 'sufficient' => [['probe:write'], true];
        yield 'insufficient' => [['probe:read'], false];
        yield 'revoked or empty' => [[], false];
        yield 'wildcard' => [['*'], true];
    }

    /** @param list<string> $scopes */
    #[DataProvider('scopes')]
    public function testSignedScopePrecedesGrantPublicationAndEffect(array $scopes, bool $allowed): void
    {
        $executed = 0;
        $container = $this->createMock(DIContainerInterface::class);
        $container->expects($allowed ? self::once() : self::never())->method('registerService')
            ->with(GrantedAuthorization::class, self::isInstanceOf(GrantedAuthorization::class));
        $op = new Operation(name: 'probe.write', description: 'Write', handler: static function () use (&$executed): array {
            ++$executed;
            return ['ok' => true];
        }, mutating: true, requiresConfirmation: true, scopes: ['probe:write']);
        $runner = new CliRunner(
            signer: $this->alwaysSigns(),
            authorizer: $this->acceptingAuthorizer(),
            signerAuthority: static fn (VerifiedSigner $signer): ToolContext => new ToolContext(principal: 'key:' . $signer->fingerprint, channel: 'cli', scopes: $scopes)
        );
        $lines = [];
        $exit = $runner->run($op, ['--sign'], $container, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        self::assertSame($allowed ? 0 : 1, $exit);
        self::assertSame($allowed ? 1 : 0, $executed);
        if (!$allowed) {
            self::assertStringContainsString('Missing required scope', implode('\n', $lines));
            self::assertStringNotContainsString('authorized by', implode('\n', $lines));
        }
    }

    public function testExplicitSignatureAuthenticatesAReadAndDoesNotLeakIntoTheNextUnsignedCall(): void
    {
        $seen = [];
        $op = new Operation(name: 'driver', description: 'A driver that receives authority', handler: static function (array $input, ?InvocationContext $context = null, ?ToolContext $authority = null) use (&$seen): array {
            $seen[] = [$context, $authority];
            return ['ok' => true];
        }, effects: EffectProfile::readOnly());
        $runner = new CliRunner(
            signer: $this->alwaysSigns(),
            authorizer: $this->acceptingAuthorizer(),
            signerAuthority: static fn (VerifiedSigner $signer): ToolContext => new ToolContext(principal: 'key:' . $signer->fingerprint, channel: 'cli', scopes: ['probe:read'])
        );
        $container = $this->createMock(DIContainerInterface::class);
        self::assertSame(0, $runner->run($op, ['--sign'], $container, static fn (string $line) => null));
        self::assertTrue($seen[0][0]->isAttributable());
        self::assertSame('cli', $seen[0][0]->channel);
        self::assertSame($seen[0][0]->actor, $seen[0][1]->principal);
        self::assertSame(['probe:read'], $seen[0][1]->scopes);
        self::assertStringStartsWith('sha256:', $seen[0][0]->authorizationId);
        self::assertSame(0, $runner->run($op, [], $container, static fn (string $line) => null));
        self::assertSame([null, null], $seen[1]);
    }

    public function testAnUnreadableAuthorityCannotPublishTheSignatureAsAGrant(): void
    {
        $container = $this->createMock(DIContainerInterface::class);
        $container->expects(self::never())->method('registerService');
        $op = new Operation(name: 'read', description: 'Read', handler: static fn () => self::fail('must not execute'), effects: EffectProfile::readOnly());
        $runner = new CliRunner(
            signer: $this->alwaysSigns(),
            authorizer: $this->acceptingAuthorizer(),
            signerAuthority: static fn (VerifiedSigner $signer) => throw new \RuntimeException('unreadable enrollment')
        );
        $lines = [];
        self::assertSame(1, $runner->run($op, ['--sign'], $container, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }));
        self::assertStringContainsString('unreadable enrollment', implode('\n', $lines));
    }
}

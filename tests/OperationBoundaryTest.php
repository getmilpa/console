<?php

/**
 * (c) Rodrigo Vicente - TeamX Agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\Console\Tests;

use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Operation;
use Milpa\Console\CliRunner;
use Milpa\Console\McpProjector;
use Milpa\Console\OperationBoundary;
use Milpa\Container\DIContainer;
use Milpa\ToolRuntime\Contracts\CallPolicy;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\Console\OperationSigner;
use Milpa\ToolRuntime\Policy\AuthorizationResult;
use Milpa\ToolRuntime\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** The current caller reaches the executor; a refusal is earlier than the signer. */
final class OperationBoundaryTest extends TestCase
{
    public function testMcpCarriesTheRegistryCallerIntoBoundaryAndHandler(): void
    {
        $container = new DIContainer();
        $context = new ToolContext(principal: 'worker', channel: 'web', scopes: ['one']);
        $boundary = $this->createMock(OperationBoundary::class);
        $boundary->expects(self::once())->method('execute')->with(self::isInstanceOf(Operation::class), ['value' => 1], $context, self::isInstanceOf(\Closure::class))
            ->willReturnCallback(static fn ($op, $input, $authority, $next) => $next());
        $container->registerService(OperationBoundary::class, $boundary);
        $operation = new Operation(name: 'read', description: '', handler: static fn (array $input, $invocation, $authority): array => [$input, $authority], effects: EffectProfile::readOnly());
        $registry = new ToolRegistry(new NullLogger());
        (new McpProjector())->projectAll([$operation], $registry, $container);
        $result = $registry->call('read', ['value' => 1], $context);
        self::assertTrue($result->success, (string) $result->error);
        self::assertSame([['value' => 1], $context], $result->data);
    }

    public function testCliConsultsHostPolicyBeforeInvokingTheSigner(): void
    {
        $container = new DIContainer();
        $policy = $this->createMock(CallPolicy::class);
        $policy->expects(self::once())->method('authorize')->willReturn(AuthorizationResult::denied('outside plugin'));
        $container->registerService(CallPolicy::class, $policy);
        $signer = $this->createMock(OperationSigner::class);
        $signer->expects(self::never())->method('sign');
        $op = new Operation(name: 'write', description: '', handler: static fn () => self::fail('must not execute'), mutating: true);
        $lines = [];
        $runner = new CliRunner(signer: $signer, callerAuthority: new ToolContext(principal: 'worker', scopes: ['one']));
        self::assertSame(1, $runner->run($op, ['--sign'], $container, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }));
        self::assertStringContainsString('outside plugin', implode('\n', $lines));
    }
}

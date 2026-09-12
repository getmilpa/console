<?php

/**
 * (c) Rodrigo Vicente - TeamX Agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\Console\Tests\Http;

use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\InvocationContext;
use Milpa\Command\Operation;
use Milpa\Console\Http\HttpProjector;
use Milpa\Http\Routing\RouteResult;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\ToolRegistry;
use Milpa\ValueObjects\Tooling\ToolOptions;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** The request's authority must survive the projector and runner without crossing requests. */
final class HttpAuthorityTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function handlers(): iterable
    {
        yield 'closure' => [false];
        yield 'class instance' => [true];
    }

    #[DataProvider('handlers')]
    public function testChildToolsReceiveOnlyThisRequestsAuthority(bool $classHandler): void
    {
        $executed = 0;
        $registry = new ToolRegistry(new NullLogger());
        $registry->register('probe_read', 'Read the probe', ['type' => 'object', 'properties' => []], static function () use (&$executed): string {
            ++$executed;
            return 'read';
        }, new ToolOptions(scopes: ['probe:read'], mutating: false));
        $driver = new HttpAuthorityDriver($registry);
        $container = $this->createMock(DIContainerInterface::class);
        $container->method('get')->willReturn($driver);
        $handler = $classHandler ? [HttpAuthorityDriver::class, 'run'] : $driver->run(...);
        $op = new Operation(name: 'driver', description: 'Originate a governed read', handler: $handler, effects: EffectProfile::readOnly());
        $psr17 = new Psr17Factory();
        $projector = new HttpProjector([$op], $container, $psr17, $psr17);
        $route = $projector->routes()[0];

        foreach ([
            [['probe:read'], true, true, 1],
            [['other:read'], true, false, 1],
            [[], true, false, 1],
            [['*'], true, true, 2],
            [['*'], false, false, 2],
        ] as [$scopes, $authenticated, $allowed, $count]) {
            $auth = new class ($scopes, $authenticated) {
                public object $actor;
                public function __construct(array $scopes, private bool $authenticated)
                {
                    $this->actor = (object) ['id' => 'caller', 'scopes' => $scopes];
                }
                public function isAuthenticated(): bool
                {
                    return $this->authenticated;
                }
            };
            $request = (new ServerRequest('GET', '/driver'))
                ->withAttribute(RouteResult::ATTRIBUTE, RouteResult::matched($route))
                ->withAttribute('milpa.auth', $auth);
            $response = $projector->handle($request);
            self::assertSame(200, $response->getStatusCode());
            $result = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($allowed, $result['allowed']);
            self::assertSame($authenticated ? $scopes : [], $result['scopes']);
            self::assertSame($count, $executed);
            self::assertSame('web', $result['attribution_channel']);
        }
        $response = $projector->handle((new ServerRequest('GET', '/driver'))->withAttribute(RouteResult::ATTRIBUTE, RouteResult::matched($route)));
        $result = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($result['allowed']);
        self::assertSame([], $result['scopes']);
        self::assertSame(2, $executed);
    }
}

/** A consumer that originates a tool call; the registry remains its authorization boundary. */
final class HttpAuthorityDriver
{
    public function __construct(private ToolRegistry $registry)
    {
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function run(array $input, ?InvocationContext $context = null, ?ToolContext $authority = null): array
    {
        return [
            'allowed' => $this->registry->call('probe_read', [], $authority)->success,
            'scopes' => $authority?->scopes,
            'attribution_channel' => $context?->channel,
        ];
    }
}

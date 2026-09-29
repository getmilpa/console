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

namespace Milpa\Console\Tests\Http;

use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Operation;
use Milpa\Command\OperationHttpPolicy;
use Milpa\Console\Http\HttpProjector;
use Milpa\Console\Http\UnguardedOperationException;
use Milpa\Container\DIContainer;
use Milpa\Http\Routing\RouteResult;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * HTTP already judged a permission-typed operation through its {@see OperationHttpPolicy}; the MCP
 * fix must leave that exactly as it was. Pinned here because nothing covered it before.
 */
final class HttpPermissionTest extends TestCase
{
    private int $ran = 0;

    private function projector(?OperationHttpPolicy $policy): HttpProjector
    {
        $op = new Operation(
            name: 'grades.read',
            description: 'Read the grades of a group',
            handler: function (): array {
                ++$this->ran;

                return ['grades' => [10, 9]];
            },
            permission: 'school.grades:read',
            effects: EffectProfile::readOnly(),
        );
        $psr17 = new Psr17Factory();

        return new HttpProjector([$op], new DIContainer(), $psr17, $psr17, policy: $policy);
    }

    private function request(HttpProjector $projector): ServerRequest
    {
        return (new ServerRequest('GET', '/grades.read'))
            ->withAttribute(RouteResult::ATTRIBUTE, RouteResult::matched($projector->routes()[0]));
    }

    public function test_without_a_policy_a_permission_typed_operation_is_a_server_error(): void
    {
        $projector = $this->projector(null);

        try {
            $projector->handle($this->request($projector));
            self::fail('a permission-typed operation with no policy must not run');
        } catch (UnguardedOperationException $e) {
            self::assertStringContainsString('school.grades:read', $e->getMessage());
        }
        self::assertSame(0, $this->ran);
    }

    public function test_the_policy_refusal_is_the_response_and_the_handler_never_runs(): void
    {
        $projector = $this->projector(new HttpPolicyDouble(allow: false));

        $response = $projector->handle($this->request($projector));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->ran);
    }

    public function test_the_policy_admits_and_the_operation_runs(): void
    {
        $policy = new HttpPolicyDouble(allow: true);
        $projector = $this->projector($policy);

        $response = $projector->handle($this->request($projector));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->ran);
        self::assertSame(['school.grades:read'], $policy->asked);
    }
}

final class HttpPolicyDouble implements OperationHttpPolicy
{
    /** @var list<string|null> */
    public array $asked = [];

    public function __construct(private readonly bool $allow)
    {
    }

    public function enforce(Operation $op, ServerRequestInterface $request): ?ResponseInterface
    {
        $this->asked[] = $op->permission;

        return $this->allow ? null : new Response(403, [], '{"error":"permission denied"}');
    }
}

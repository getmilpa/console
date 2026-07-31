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

use Milpa\Command\Operation;
use Milpa\Console\Http\HttpProjector;
use Milpa\Console\Http\OperationHttpPolicy;
use Milpa\Console\Http\UnguardedOperationException;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\RouteResult;
use Milpa\Interfaces\Di\DIContainerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * La proyección HTTP de una operación, ya en la casa donde viven los otros tres projectors.
 *
 * Vino de `milpa/skeleton`, que era su única casa: cuando skeleton se retiró como puerta de entrada
 * (P14.3), ésta era la capacidad que se iba con él — la ÚNICA forma que tiene la familia de exponer
 * un átomo por HTTP. Lo que cambió en la mudanza es que la identidad quedó del otro lado de
 * {@see OperationHttpPolicy}, y estas pruebas lo miden: corren SIN `milpa/auth` instalado.
 */
final class HttpProjectorTest extends TestCase
{
    // Unscoped on purpose: these cases exercise the confirm gate + coercion in isolation, which is
    // the byte-identical empty-scope path. Scope enforcement (the closed Artifact 09 hole) has its
    // own dedicated fixture and suite in HttpProjectorScopeEnforcementTest.
    private function createPostOperation(): Operation
    {
        return new Operation(
            name: 'create_post',
            description: 'Create a post',
            handler: static fn (array $i): array => ['id' => 1] + $i,
            inputSchema: ['type' => 'object', 'properties' => [
                'title' => ['type' => 'string'],
                'body' => ['type' => 'string'],
            ], 'required' => ['title', 'body']],
            mutating: true,
            requiresConfirmation: true,
            path: '/posts',
        );
    }

    private function projector(Operation ...$ops): HttpProjector
    {
        $psr17 = new Psr17Factory();

        return new HttpProjector($ops, $this->createMock(DIContainerInterface::class), $psr17, $psr17);
    }

    public function testSynthesizesOneRoutePerOperationWithVerbFromMutating(): void
    {
        $routes = $this->projector($this->createPostOperation())->routes();

        self::assertCount(1, $routes);
        self::assertSame('/posts', $routes[0]->path);
        self::assertSame([HttpMethod::POST], $routes[0]->methods);
        self::assertSame('create_post', $routes[0]->name);
        self::assertNotNull($routes[0]->handler);
    }

    public function testDerivesPathFromNameWhenNotDeclared(): void
    {
        $op = new Operation('board:seed', 'Seed', static fn (array $i): array => $i, inputSchema: ['type' => 'object']);
        $routes = $this->projector($op)->routes();

        self::assertSame('/board/seed', $routes[0]->path);
        self::assertSame([HttpMethod::GET], $routes[0]->methods); // not mutating -> GET
    }

    public function testMutatingRequestWithoutTokenReturns428WithAToken(): void
    {
        $projector = $this->projector($this->createPostOperation());
        $request = $this->matched($projector, 'POST', '/posts', '{"title":"Hi","body":"Yo"}');

        $response = $projector->handle($request);

        self::assertSame(428, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertTrue($payload['requires_confirmation']);
        self::assertNotEmpty($payload['confirm_token']);
    }

    public function testConfirmedRequestCreatesAndReturns201(): void
    {
        $projector = $this->projector($this->createPostOperation());
        $token = json_decode((string) $projector->handle(
            $this->matched($projector, 'POST', '/posts', '{"title":"Hi","body":"Yo"}')
        )->getBody(), true)['confirm_token'];

        $confirmed = $this->matched($projector, 'POST', '/posts', '{"title":"Hi","body":"Yo"}')
            ->withHeader('Confirm-Token', $token);

        $response = $projector->handle($confirmed);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['id' => 1, 'title' => 'Hi', 'body' => 'Yo'], json_decode((string) $response->getBody(), true));
    }

    public function testInvalidBodyReturns422(): void
    {
        $projector = $this->projector($this->createPostOperation());
        $confirmed = $this->matched($projector, 'POST', '/posts', '{"title":"Hi"}') // body missing
            ->withHeader('Confirm-Token', json_decode((string) $projector->handle(
                $this->matched($projector, 'POST', '/posts', '{"title":"Hi"}')
            )->getBody(), true)['confirm_token']);

        $response = $projector->handle($confirmed);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('body', (string) $response->getBody());
    }

    public function testDerivedPathWithEmptySegmentThrowsAtRouteSynthesis(): void
    {
        // 'bad::name' -> explode(':', ...) -> ['bad', '', 'name'] -> an empty middle segment,
        // which the Router's grammar cannot express (would produce a `//` in the path).
        $op = new Operation('bad::name', 'Bad', static fn (array $i): array => $i, inputSchema: ['type' => 'object']);
        $projector = $this->projector($op);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/bad::name/');

        $projector->routes();
    }

    public function testDerivedPathContainingBraceThrowsAtRouteSynthesis(): void
    {
        // A name containing '{' would accidentally synthesize a Router placeholder segment.
        $op = new Operation('board:{seed}', 'Seed', static fn (array $i): array => $i, inputSchema: ['type' => 'object']);
        $projector = $this->projector($op);

        $this->expectException(\InvalidArgumentException::class);

        $projector->routes();
    }

    public function testUnknownOperationReturns404(): void
    {
        $projector = $this->projector($this->createPostOperation());
        // a matched RouteResult whose route name is not one of the projector's operations
        $stray = new \Milpa\Http\Routing\Route('/other', HttpMethod::GET, name: 'ghost');
        $request = (new ServerRequest('GET', '/other'))
            ->withAttribute(RouteResult::ATTRIBUTE, RouteResult::matched($stray));

        self::assertSame(404, $projector->handle($request)->getStatusCode());
    }

    private function matched(HttpProjector $projector, string $method, string $path, string $body): ServerRequest
    {
        $route = null;
        foreach ($projector->routes() as $r) {
            if ($r->path === $path) {
                $route = $r;
                break;
            }
        }
        self::assertNotNull($route, "no synthesized route for {$path}");

        return (new ServerRequest($method, $path, ['Content-Type' => 'application/json'], $body))
            ->withAttribute(RouteResult::ATTRIBUTE, RouteResult::matched($route));
    }

    public function testItNamesItsSurfaceAndClaimsOnlyOperationsThatOfferIt(): void
    {
        // The projector registry routes by these two answers. A projector that
        // claimed every operation would expose CLI-only ones over HTTP.
        $psr17 = new Psr17Factory();
        $projector = new HttpProjector([], $this->createMock(DIContainerInterface::class), $psr17, $psr17);
        $solaHttp = new Operation('solo_http', 'Solo HTTP', static fn (array $i): array => $i, surfaces: ['http']);
        $solaCli = new Operation('solo_cli', 'Solo CLI', static fn (array $i): array => $i, surfaces: ['cli']);

        self::assertSame('http', $projector->surface());
        self::assertTrue($projector->supports($solaHttp));
        self::assertFalse($projector->supports($solaCli), 'A CLI-only operation is not reachable over the wire.');
    }

    /**
     * Una operación con scopes y SIN política cableada se niega con un 500 — no corre.
     *
     * Es la mitad que no se puede perder de la mudanza: la política es opcional para el proyector
     * (un host sin identidad expone operaciones sin scopes y nunca la toca), pero «opcional» no puede
     * significar «entonces adelante». Correr una operación protegida sin nadie que mire sus scopes es
     * exactamente el agujero que esta capa cerró.
     */
    public function testAScopedOperationWithoutAPolicyRefusesInsteadOfRunningNaked(): void
    {
        $op = new Operation(
            name: 'protegida',
            description: 'Protegida',
            handler: static fn (array $i): array => $i,
            inputSchema: ['type' => 'object'],
            scopes: ['posts:write'],
            path: '/protegida',
        );
        $projector = $this->projector($op);

        $this->expectException(UnguardedOperationException::class);
        $this->expectExceptionMessageMatches('/posts:write/');

        $projector->handle($this->matched($projector, 'GET', '/protegida', ''));
    }

    /** Con política cableada, una negativa de la política ES la respuesta — el handler no corre. */
    public function testThePolicysRefusalIsTheAnswerAndTheHandlerNeverRuns(): void
    {
        $corrio = false;
        $op = new Operation(
            name: 'protegida',
            description: 'Protegida',
            handler: static function (array $i) use (&$corrio): array {
                $corrio = true;

                return $i;
            },
            inputSchema: ['type' => 'object'],
            scopes: ['posts:write'],
            path: '/protegida',
        );

        $psr17 = new Psr17Factory();
        $politica = new class ($psr17) implements OperationHttpPolicy {
            public function __construct(private readonly Psr17Factory $psr17)
            {
            }

            public function enforce(Operation $op, ServerRequestInterface $request): ?ResponseInterface
            {
                return $this->psr17->createResponse(403)->withBody($this->psr17->createStream('{"code":"NEGADO"}'));
            }
        };

        $projector = new HttpProjector([$op], $this->createMock(DIContainerInterface::class), $psr17, $psr17, policy: $politica);

        $respuesta = $projector->handle($this->matched($projector, 'GET', '/protegida', ''));

        self::assertSame(403, $respuesta->getStatusCode());
        self::assertStringContainsString('NEGADO', (string) $respuesta->getBody());
        self::assertFalse($corrio, 'una operación negada no puede haber corrido de todos modos');
    }

    /** Y una operación SIN scopes ni permiso nunca consulta a la política, aunque esté cableada. */
    public function testAnUnprotectedOperationNeverConsultsThePolicy(): void
    {
        $consultada = false;
        $politica = new class ($consultada) implements OperationHttpPolicy {
            public function __construct(private bool &$consultada)
            {
            }

            public function enforce(Operation $op, ServerRequestInterface $request): ?ResponseInterface
            {
                $this->consultada = true;

                return null;
            }
        };

        $op = new Operation('libre', 'Libre', static fn (array $i): array => ['ok' => true], inputSchema: ['type' => 'object'], path: '/libre');
        $psr17 = new Psr17Factory();
        $projector = new HttpProjector([$op], $this->createMock(DIContainerInterface::class), $psr17, $psr17, policy: $politica);

        self::assertSame(200, $projector->handle($this->matched($projector, 'GET', '/libre', ''))->getStatusCode());
        self::assertFalse($consultada);
    }
}

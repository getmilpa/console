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
use Milpa\Console\Events\OperationExecutedEvent;
use Milpa\Console\Events\OperationExecutingEvent;
use Milpa\Console\OperationRunner;
use Milpa\Console\OperationStoppedException;
use Milpa\Events\InterceptionSlot;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * El único lugar donde una operación se ejecuta — y por lo tanto el único donde un gancho la ve.
 *
 * Había cuatro caminos: la terminal resolvía el handler y lo llamaba, HTTP hacía lo mismo, el TUI
 * otra vez, y sólo MCP emitía eventos porque pasaba por el registry de herramientas. Un listener que
 * auditara una operación que muta la veía por una superficie y no por las otras tres.
 */
final class OperationRunnerTest extends TestCase
{
    private function container(): ContainerInterface
    {
        return new class () implements ContainerInterface {
            public function get(string $id): mixed
            {
                return new class () {
                    /** @param array<string, mixed> $input */
                    public function hazlo(array $input): array
                    {
                        return ['ok' => true, 'desde' => 'el contenedor'] + $input;
                    }
                };
            }

            public function has(string $id): bool
            {
                return true;
            }
        };
    }

    /**
     * Un despachador que apunta lo que pasó y deja que un listener intervenga.
     *
     * @param \Closure(string, array<string, mixed>): void|null $listener
     */
    private function dispatcher(array &$vistos, ?\Closure $listener = null): MilpaEventDispatcherInterface
    {
        return new class ($vistos, $listener) implements MilpaEventDispatcherInterface {
            /**
             * @param list<array{0: string, 1: mixed}>                  $vistos
             * @param \Closure(string, array<string, mixed>): void|null $listener
             */
            public function __construct(private array &$vistos, private readonly ?\Closure $listener)
            {
            }

            public function dispatch(string $eventName, array $payload = [], bool $async = false): void
            {
                $this->vistos[] = [$eventName, $payload['event'] ?? null];
                if ($this->listener !== null) {
                    ($this->listener)($eventName, $payload);
                }
            }

            public function subscribe(string $eventName, callable $handler, int $priority = 0): void
            {
            }

            public function getSubscribers(string $eventName): array
            {
                return [];
            }

            public function hasSubscribers(string $eventName): bool
            {
                return false;
            }
        };
    }

    private function operacion(): Operation
    {
        return new Operation(
            name: 'algo',
            description: 'Algo',
            handler: static fn (array $i): array => ['ok' => true, 'eco' => $i['x'] ?? null],
            inputSchema: ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]],
        
            effects: new EffectProfile(
                mutation: Mutation::None,
                externality: Externality::None,
                reversibility: Reversibility::Guaranteed,
                authority: Authority::Read,
                subject: Subject::None,
                rollbackContract: 'test probe: nothing leaves this process',
            ),
        );
    }

    /** El camino feliz: se emite antes, corre, y se emite después. */
    public function testItAnnouncesBeforeAndAfter(): void
    {
        $vistos = [];
        $r = (new OperationRunner($this->container(), $this->dispatcher($vistos)))
            ->run($this->operacion(), ['x' => 'hola'], 'cli');

        self::assertSame(['ok' => true, 'eco' => 'hola'], $r);
        self::assertSame(['operation.executing', 'operation.executed'], array_column($vistos, 0));

        $antes = $vistos[0][1];
        self::assertInstanceOf(OperationExecutingEvent::class, $antes);
        self::assertSame('algo', $antes->operation->name);
        self::assertSame('cli', $antes->surface, 'el evento dice POR DÓNDE entró');

        $despues = $vistos[1][1];
        self::assertInstanceOf(OperationExecutedEvent::class, $despues);
        self::assertTrue($despues->ran());
        self::assertSame(['ok' => true, 'eco' => 'hola'], $despues->result);
    }

    /**
     * Un listener puede CONTESTAR por el handler — y la auditoría lo dice.
     *
     * Es como se enchufa un caché. Que el resultado servido aparezca marcado importa: un resultado
     * que no aparece en la bitácora es un hueco que sólo se nota cuando alguien pregunta por qué no
     * aparece.
     */
    public function testAListenerCanAnswerInsteadOfTheHandlerAndTheAuditSaysSo(): void
    {
        $corrio = false;
        $op = new Operation(
            name: 'cara',
            description: 'Cara',
            handler: static function (array $i) use (&$corrio): array {
                $corrio = true;

                return ['ok' => true, 'de' => 'el handler'];
            },
            inputSchema: ['type' => 'object', 'properties' => []],
        
            effects: new EffectProfile(
                mutation: Mutation::None,
                externality: Externality::None,
                reversibility: Reversibility::Guaranteed,
                authority: Authority::Read,
                subject: Subject::None,
                rollbackContract: 'test probe: nothing leaves this process',
            ),
        );

        $vistos = [];
        $despachador = $this->dispatcher($vistos, static function (string $nombre, array $payload): void {
            if ($nombre === 'operation.executing') {
                $slot = $payload['slot'];
                self::assertInstanceOf(InterceptionSlot::class, $slot);
                $slot->shortCircuit(['ok' => true, 'de' => 'el caché']);
            }
        });

        $r = (new OperationRunner($this->container(), $despachador))->run($op, [], 'mcp');

        self::assertSame(['ok' => true, 'de' => 'el caché'], $r);
        self::assertFalse($corrio, 'el handler no puede haber corrido');

        $despues = $vistos[1][1];
        self::assertInstanceOf(OperationExecutedEvent::class, $despues);
        self::assertTrue($despues->shortCircuited);
        self::assertFalse($despues->ran());
    }

    /**
     * Un listener puede DETENERLA, y detener no es contestar.
     *
     * Nadie devolvió un resultado, así que devolver `null` sería indistinguible de una operación que
     * corrió y no devolvió nada. Se lanza, y quien llamó decide qué hacer con eso en su superficie.
     */
    public function testAListenerCanStopItAndStoppingIsNotAnswering(): void
    {
        $corrio = false;
        $op = new Operation(
            name: 'prohibida',
            description: 'Prohibida',
            handler: static function (array $i) use (&$corrio): array {
                $corrio = true;

                return ['ok' => true];
            },
            inputSchema: ['type' => 'object', 'properties' => []],
            mutating: true,
        
            effects: new EffectProfile(
                mutation: Mutation::Persistent,
                externality: Externality::None,
                reversibility: Reversibility::Guaranteed,
                authority: Authority::Read,
                subject: Subject::Data,
                rollbackContract: 'test probe: nothing leaves this process',
            ),
        );

        $vistos = [];
        $despachador = $this->dispatcher($vistos, static function (string $nombre, array $payload): void {
            if ($nombre === 'operation.executing') {
                $payload['slot']->stop();
            }
        });

        try {
            (new OperationRunner($this->container(), $despachador))->run($op, [], 'http');
            self::fail('detener tiene que ser distinguible de contestar');
        } catch (OperationStoppedException $e) {
            self::assertStringContainsString('prohibida', $e->getMessage());
        }

        self::assertFalse($corrio);
        $despues = $vistos[1][1];
        self::assertInstanceOf(OperationExecutedEvent::class, $despues);
        self::assertTrue($despues->stopped);
        self::assertFalse($despues->shortCircuited, 'detenida y cortocircuitada son cosas distintas');
    }

    /**
     * Un handler que lanza SE AUDITA antes de propagar.
     *
     * Un error que no deja rastro es el que nadie encuentra al día siguiente.
     */
    public function testAFailureIsAuditedBeforeItPropagates(): void
    {
        $op = new Operation(
            name: 'truena',
            description: 'Truena',
            handler: static function (array $i): array {
                throw new \RuntimeException('se cayó la base');
            },
            inputSchema: ['type' => 'object', 'properties' => []],
        
            effects: new EffectProfile(
                mutation: Mutation::None,
                externality: Externality::None,
                reversibility: Reversibility::Guaranteed,
                authority: Authority::Read,
                subject: Subject::None,
                rollbackContract: 'test probe: nothing leaves this process',
            ),
        );

        $vistos = [];
        try {
            (new OperationRunner($this->container(), $this->dispatcher($vistos)))->run($op, [], 'tui');
            self::fail('el error tiene que propagarse');
        } catch (\RuntimeException $e) {
            self::assertSame('se cayó la base', $e->getMessage());
        }

        $despues = $vistos[1][1];
        self::assertInstanceOf(OperationExecutedEvent::class, $despues);
        self::assertNotNull($despues->error);
        self::assertFalse($despues->ran());
    }

    /** Un handler declarado como `[Clase, 'metodo']` se resuelve del contenedor. */
    public function testAClassMethodHandlerIsResolvedFromTheContainer(): void
    {
        $op = new Operation(
            name: 'por-contenedor',
            description: 'Por contenedor',
            handler: [\stdClass::class, 'hazlo'],
            inputSchema: ['type' => 'object', 'properties' => []],
        
            effects: new EffectProfile(
                mutation: Mutation::None,
                externality: Externality::None,
                reversibility: Reversibility::Guaranteed,
                authority: Authority::Read,
                subject: Subject::None,
                rollbackContract: 'test probe: nothing leaves this process',
            ),
        );

        $vistos = [];
        $r = (new OperationRunner($this->container(), $this->dispatcher($vistos)))->run($op, ['y' => 1], 'cli');

        self::assertSame(['ok' => true, 'desde' => 'el contenedor', 'y' => 1], $r);
    }

    /**
     * Sin despachador cableado, corre exactamente igual.
     *
     * Los ganchos son opcionales; ejecutar no. Un host que no cablea eventos pierde los primeros y
     * conserva lo segundo.
     */
    public function testWithoutADispatcherItStillRuns(): void
    {
        $r = (new OperationRunner($this->container()))->run($this->operacion(), ['x' => 'sin eventos'], 'cli');

        self::assertSame(['ok' => true, 'eco' => 'sin eventos'], $r);
    }

    /** El veredicto: `ok` booleano en la raíz manda; su ausencia es un acierto, no un fallo. */
    public function testTheVerdictReadsOkAndNothingElse(): void
    {
        self::assertTrue(OperationRunner::verdict(['ok' => true, 'x' => 1]));
        self::assertFalse(OperationRunner::verdict(['ok' => false]));
        self::assertTrue(OperationRunner::verdict(['datos' => [1, 2]]), 'sin `ok` no hay veredicto que leer');
        self::assertTrue(OperationRunner::verdict('una cadena'));
        self::assertTrue(OperationRunner::verdict(['ok' => 'sí']), 'un `ok` que no es booleano no es un veredicto');
    }

    /**
     * LAS CUATRO superficies pasan por el mismo gancho — que es la razón de que esta clase exista.
     *
     * Es la prueba que no se podía escribir antes: cada superficie resolvía el handler por su cuenta,
     * así que un listener veía una de cuatro. Si alguien vuelve a llamar al handler directo desde una
     * superficie, esta lista se queda corta y lo dice.
     */
    public function testTheFourSurfacesReachTheSameHook(): void
    {
        $vistos = [];
        $despachador = $this->dispatcher($vistos);
        $op = new Operation(
            name: 'ping',
            description: 'Ping',
            handler: static fn (array $i): array => ['ok' => true],
            inputSchema: ['type' => 'object', 'properties' => []],
            path: '/ping',
        
            effects: new EffectProfile(
                mutation: Mutation::None,
                externality: Externality::None,
                reversibility: Reversibility::Guaranteed,
                authority: Authority::Read,
                subject: Subject::None,
                rollbackContract: 'test probe: nothing leaves this process',
            ),
        );
        $contenedor = new \Milpa\Container\DIContainer();
        $psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();

        // 1. terminal
        (new \Milpa\Console\CliRunner(dispatcher: $despachador))
            ->run($op, [], $contenedor, static fn (string $l) => null);

        // 2. TUI
        (new \Milpa\Console\Tui\OperationScreen($op, $contenedor, 40, 10, false, dispatcher: $despachador))
            ->press('enter');

        // 3. HTTP
        $proyector = new \Milpa\Console\Http\HttpProjector([$op], $contenedor, $psr17, $psr17, dispatcher: $despachador);
        $ruta = $proyector->routes()[0];
        $proyector->handle(
            (new \Nyholm\Psr7\ServerRequest('GET', '/ping'))->withAttribute(
                \Milpa\Http\Routing\RouteResult::ATTRIBUTE,
                \Milpa\Http\Routing\RouteResult::matched($ruta),
            ),
        );

        // 4. MCP
        $registry = new \Milpa\ToolRuntime\ToolRegistry(new \Psr\Log\NullLogger());
        (new \Milpa\Console\McpProjector($despachador))->projectAll([$op], $registry, $contenedor);
        $registry->call('ping', [], \Milpa\ToolRuntime\Contracts\ToolContext::cli('prueba'));

        $superficies = [];
        foreach ($vistos as [$nombre, $evento]) {
            if ($nombre === 'operation.executed' && $evento instanceof OperationExecutedEvent) {
                $superficies[] = $evento->surface;
            }
        }

        self::assertSame(['cli', 'tui', 'http', 'mcp'], $superficies);
    }
}

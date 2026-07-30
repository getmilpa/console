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

use Milpa\Command\Operation;
use Milpa\Command\SurfaceModel;
use Milpa\Console\McpProjector;
use Milpa\Container\DIContainer;
use Milpa\Interfaces\Tooling\ToolRegistryInterface;
use Milpa\ValueObjects\Tooling\ToolOptions;
use PHPUnit\Framework\TestCase;

/**
 * La proyección a MCP, ahora partida en dos: `project()` produce un valor, `materialize()` escribe.
 *
 * Esta prueba es el dividendo medible de ADR-0035. La versión anterior tenía que fabricar un
 * `ToolRegistryInterface` anónimo con una propiedad POR REFERENCIA para capturar los argumentos de
 * `register()`, más un mock del contenedor — y ese espía existía por una sola razón: el projector no
 * devolvía nada, proyectaba mutando a un colaborador, así que la única forma de ver la proyección era
 * interceptar la mutación.
 *
 * Con `project(Operation): SurfaceModel` la mitad de arriba se prueba con aserciones sobre un valor
 * de retorno: cero mocks, cero espías, cero `&$captured`. El espía sobrevive únicamente donde de
 * verdad hace falta — en `materialize()`, que sí escribe.
 */
final class McpProjectorTest extends TestCase
{
    private function operacion(): Operation
    {
        return new Operation(
            name: 'create_post',
            description: 'Create a post',
            handler: [PostHandlerDoble::class, 'crear'],
            inputSchema: ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]],
            mutating: true,
            requiresConfirmation: true,
            scopes: ['posts:write'],
        );
    }

    public function test_proyectar_devuelve_el_modelo_sin_colaboradores(): void
    {
        $modelo = (new McpProjector())->project($this->operacion());

        self::assertInstanceOf(SurfaceModel::class, $modelo);
        self::assertSame('mcp', $modelo->surface());
        self::assertSame('create_post', $modelo->name);
        self::assertSame('Create a post', $modelo->description);
        self::assertSame(['type' => 'object', 'properties' => ['title' => ['type' => 'string']]], $modelo->inputSchema);
        self::assertTrue($modelo->mutating);
        self::assertTrue($modelo->requiresConfirmation);
        self::assertSame(['posts:write'], $modelo->scopes);
    }

    /**
     * El handler viaja como referencia y no como callable resuelto: es lo que hace al modelo
     * serializable, y lo que permite que un renderer alternativo lo consuma.
     */
    public function test_el_modelo_lleva_el_handler_sin_resolver_y_serializa(): void
    {
        $modelo = (new McpProjector())->project($this->operacion());

        self::assertSame([PostHandlerDoble::class, 'crear'], $modelo->handler);

        $plano = $modelo->toArray();
        self::assertSame(PostHandlerDoble::class . '::crear', $plano['handler']);
        self::assertSame('mcp', $plano['surface']);
        self::assertJson(json_encode($plano, JSON_THROW_ON_ERROR), 'un modelo serializable sobrevive a json_encode');
    }

    /**
     * Proyectar es inerte: dos veces da el mismo valor y no ocurre nada. Antes, la segunda llamada
     * lanzaba `ToolAlreadyRegisteredException` — el error salía de proyectar, no de escribir.
     */
    public function test_proyectar_dos_veces_es_inofensivo(): void
    {
        $p = new McpProjector();
        $op = $this->operacion();

        self::assertEquals($p->project($op)->toArray(), $p->project($op)->toArray());
    }

    /** Y materializar dos veces SÍ falla, que es donde el error pertenece: al que escribe. */
    public function test_materializar_dos_veces_el_mismo_nombre_falla(): void
    {
        $p = new McpProjector();
        $modelo = $p->project($this->operacion());
        $registry = new RegistryEspia();

        $p->materialize($modelo, $registry, new DIContainer());
        self::assertCount(1, $registry->registrados);

        $registry->lanzaEnDuplicado = true;
        $this->expectException(\RuntimeException::class);
        $p->materialize($modelo, $registry, new DIContainer());
    }

    /** Materializar traduce el modelo a la llamada del registry, resolviendo el handler. */
    public function test_materializar_registra_y_resuelve_el_handler(): void
    {
        $p = new McpProjector();
        $registry = new RegistryEspia();
        $container = new DIContainer();
        $container->registerService(PostHandlerDoble::class, new PostHandlerDoble());

        $p->materialize($p->project($this->operacion()), $registry, $container);

        $r = $registry->registrados[0];
        self::assertSame('create_post', $r['name']);
        self::assertInstanceOf(ToolOptions::class, $r['options']);
        self::assertTrue($r['options']->mutating);
        self::assertSame(['posts:write'], $r['options']->scopes);
        self::assertSame(['id' => 1, 'title' => 'Hi'], ($r['callback'])(['title' => 'Hi']));
    }

    public function test_projectAll_salta_las_operaciones_que_no_ofrecen_esta_superficie(): void
    {
        $registry = new RegistryEspia();
        $soloCli = new Operation('cli_only', 'x', static fn (array $i) => $i, surfaces: ['cli']);

        (new McpProjector())->projectAll([$soloCli, $this->operacion()], $registry, new DIContainer());

        self::assertSame(['create_post'], array_column($registry->registrados, 'name'));
    }

    public function test_nombra_su_superficie_y_reclama_solo_lo_que_la_ofrece(): void
    {
        $p = new McpProjector();

        self::assertSame('mcp', $p->surface());
        self::assertTrue($p->supports(new Operation('a', 'x', static fn () => null)));
        self::assertTrue($p->supports(new Operation('b', 'x', static fn () => null, surfaces: ['mcp', 'cli'])));
        self::assertFalse($p->supports(new Operation('c', 'x', static fn () => null, surfaces: ['cli'])));
    }
}

/** El doble del handler que la operación referencia por clase y método. */
final class PostHandlerDoble
{
    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public function crear(array $args): array
    {
        return ['id' => 1] + $args;
    }
}

/**
 * El espía sobrevive SÓLO aquí: `materialize()` escribe, y para ver una escritura hay que mirar a
 * quien la recibe. Es la mitad que legítimamente necesita un colaborador.
 */
final class RegistryEspia implements ToolRegistryInterface
{
    /** @var list<array<string, mixed>> */
    public array $registrados = [];

    public bool $lanzaEnDuplicado = false;

    /** @param array<string, mixed> $inputSchema */
    public function register(
        string $name,
        string $description,
        array $inputSchema,
        callable $callback,
        ?ToolOptions $options = null
    ): void {
        if ($this->lanzaEnDuplicado) {
            throw new \RuntimeException("Tool already registered: {$name}");
        }

        $this->registrados[] = compact('name', 'description', 'inputSchema', 'callback', 'options');
    }
}

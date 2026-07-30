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

namespace Milpa\Console;

use Milpa\Command\Operation;
use Milpa\Command\SurfaceProjector;
use Milpa\Console\Model\McpToolModel;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Tooling\ToolRegistryInterface;
use Milpa\ValueObjects\Tooling\ToolOptions;

/**
 * Projects Operations to the MCP surface by registering each into the tool registry. Because atoms
 * register through the same ToolRegistry the family already exposes over JSON-RPC, MCP transport
 * (tools/list + tools/call) and the confirm-token gate come for free from tool-runtime — this
 * projector only maps the atom's metadata into a ToolOptions. It references only milpa/core types
 * (always present), so it needs no class_exists guard; the guard lives at the call site where the
 * concrete ToolRegistry is constructed.
 */
final class McpProjector implements SurfaceProjector
{
    /** The surface tag this projector answers for — `mcp`. */
    public function surface(): string
    {
        return 'mcp';
    }

    /** Whether the operation opted into this surface; `surfaces: null` means every one. */
    public function supports(Operation $op): bool
    {
        return $op->supportsSurface('mcp');
    }

    /**
     * Proyecta la operación a la herramienta que un agente verá: nombre, descripción, esquema y las
     * banderas de política. Devuelve un valor, no toca nada.
     *
     * El handler viaja como REFERENCIA. Resolverlo contra el contenedor le toca a
     * {@see self::materialize()}, y por eso este modelo se puede serializar, comparar y contar sin
     * causar nada — que es lo que necesitaban los dos lectores que antes tenían que registrar para
     * poder leer.
     */
    public function project(Operation $op): McpToolModel
    {
        return new McpToolModel(
            name: $op->name,
            description: $op->description,
            inputSchema: $op->inputSchema ?? [],
            handler: $op->handler,
            scopes: $op->scopes,
            mutating: $op->mutating,
            requiresConfirmation: $op->requiresConfirmation,
            version: $op->version,
            outputSchema: $op->outputSchema,
        );
    }

    /**
     * Materializa un modelo en el registry, que después lo sirve por JSON-RPC como `tools/list` y
     * `tools/call`.
     *
     * Aquí SÍ se escribe, y por eso aquí es donde registrar dos veces el mismo nombre debe fallar —
     * el registry lo hace, lanzando `ToolAlreadyRegisteredException`. Antes ese error salía de
     * proyectar, que es lo que volvía peligroso llamar a `project()` dos veces en un mismo proceso.
     */
    public function materialize(McpToolModel $model, ToolRegistryInterface $registry, DIContainerInterface $container): void
    {
        $registry->register(
            $model->name,
            $model->description,
            $model->inputSchema,
            $this->callableFrom($model->handler, $container),
            new ToolOptions(
                scopes: $model->scopes,
                mutating: $model->mutating,
                requiresConfirmation: $model->requiresConfirmation,
                version: $model->version,
                outputSchema: $model->outputSchema,
            ),
        );
    }

    /**
     * Proyecta y materializa cada operación soportada — el camino que usa un host que sí quiere el
     * efecto.
     *
     * Se conserva porque separar las dos mitades no debe obligar a cada llamador a coserlas otra vez;
     * lo que cambia es que ahora existe la mitad que NO escribe, para quien sólo quiere mirar.
     *
     * @param iterable<Operation> $operations
     */
    public function projectAll(iterable $operations, ToolRegistryInterface $registry, DIContainerInterface $container): void
    {
        foreach ($operations as $op) {
            if (!$this->supports($op)) {
                continue;
            }

            $this->materialize($this->project($op), $registry, $container);
        }
    }

    /**
     * @param callable|array{0: class-string, 1: string} $handler
     *
     * @return callable(array<string, mixed>): mixed
     */
    private function callableFrom(mixed $handler, DIContainerInterface $container): callable
    {
        if (\is_callable($handler)) {
            return $handler;
        }

        [$class, $method] = $handler;

        return static fn (array $args): mixed => $container->get($class)->{$method}($args);
    }
}

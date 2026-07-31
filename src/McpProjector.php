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
 *
 * ── EL NOMBRE SE NORMALIZA A LO QUE MCP ACEPTA ──────────────────────────────────────────────────
 *
 * La spec de MCP acota un nombre de herramienta a `[a-zA-Z0-9_-]`, hasta 64 caracteres. La familia no
 * usa una sola convención para nombrar átomos —el host escribe `agent_context`, `milpa/plugin`
 * escribe `plugins.list`— y sólo una de las dos le sirve a esta superficie: un cliente MCP RECHAZA
 * `plugins.list`.
 *
 * Así que se traduce aquí, que es donde cada superficie ya traduce a su convención: el materializador
 * de una terminal convierte `_` en `:` porque una terminal expresa jerarquía con dos puntos, y esto
 * convierte todo lo que MCP no admite en `_` por la misma razón. Ninguna de las dos convenciones
 * tiene que ceder, y ningún paquete tiene que renombrar sus átomos para poder proyectarse.
 *
 * La alternativa medida era renombrar los siete átomos de `milpa/plugin`: cinco releases en cascada
 * para arreglar UNA instancia, contra tres para arreglar la clase entera. Y el siguiente paquete que
 * eligiera un nombre con punto lo habría repetido.
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
     * El nombre que MCP acepta para un átomo: todo lo que no sea `[a-zA-Z0-9_-]` se vuelve `_`.
     *
     * Es determinista y sin estado, así que dos hosts distintos proyectan el mismo átomo al mismo
     * nombre de herramienta. Un mapeo por host habría hecho que un agente aprendiera un nombre en un
     * despliegue y otro en el siguiente.
     *
     * El recorte a 64 caracteres es de la spec. Se corta por el final porque el prefijo es lo que
     * identifica —`plugins_`, `governance_`— y perderlo dejaría nombres indistinguibles entre sí.
     */
    public static function toolName(string $operationName): string
    {
        $normalizado = (string) preg_replace('/[^a-zA-Z0-9_-]/', '_', $operationName);

        return mb_strlen($normalizado) > 64 ? mb_substr($normalizado, 0, 64) : $normalizado;
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
            name: self::toolName($op->name),
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

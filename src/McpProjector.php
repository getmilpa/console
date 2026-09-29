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
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Tooling\ToolRegistryInterface;
use Milpa\ValueObjects\Tooling\ToolOptions;
use Psr\Log\LoggerInterface;

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
    /** @var array<string, string> tool name => why it was not served */
    private array $withheld = [];

    /**
     * El despachador es opcional y viaja al {@see OperationRunner} que materializa cada herramienta:
     * con él, una operación llamada por un agente emite los mismos eventos que si la hubieran corrido
     * en la terminal. Sin él, corre igual.
     */
    public function __construct(private readonly ?MilpaEventDispatcherInterface $dispatcher = null)
    {
    }

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
            requiresConfirmation: Consent::demanded($op),
            version: $op->version,
            outputSchema: $op->outputSchema,
            operation: $op,
            permission: $op->permission,
        );
    }

    /**
     * The tools this projector refused to serve, by tool name, with the reason.
     *
     * A permission-typed operation is served only behind an {@see OperationMcpPolicy}; without one
     * it lands here (and in a warning on the container's logger) instead of being served unjudged.
     *
     * @return array<string, string>
     */
    public function withheld(): array
    {
        return $this->withheld;
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
        $host = $container->has(\Milpa\ToolRuntime\Contracts\CallPolicy::class) ? $container->get(\Milpa\ToolRuntime\Contracts\CallPolicy::class) : null;
        $host = $host instanceof \Milpa\ToolRuntime\Contracts\CallPolicy ? $host : null;
        $gate = $registry instanceof \Milpa\ToolRuntime\ToolRegistry ? $registry->getPolicyGate() : null;
        $installed = $gate?->getCallPolicy();
        if ($installed instanceof PermissionCallPolicy) {
            // A permission-typed tool was materialized on this registry before: its judge stays in
            // the gate, and the host's policy is refreshed behind it instead of replacing it.
            $installed->deferTo($host);
        } elseif ($host !== null) {
            $gate?->setCallPolicy($host);
        }

        $callable = $model->permission !== null
            ? $this->guarded($model, $container, $gate)
            : $this->callableFrom($model, $container);
        if ($callable === null) {
            return;
        }

        $registry->register(
            $model->name,
            $model->description,
            $model->inputSchema,
            $callable,
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
     * Wraps a permission-typed tool in the host's {@see OperationMcpPolicy}, or withholds it.
     *
     * Up to 0.22.1 the permission was dropped here: only `scopes` reached the registry, so the
     * PolicyGate saw a tool with no authority declared and served it to every caller. Withholding
     * is the fallback, never silence: the tool is not registered, {@see self::withheld()} names it
     * and the container's logger (if any) says why.
     *
     * When the registry is tool-runtime's, the judge is installed in its PolicyGate
     * ({@see PermissionCallPolicy}), so the call is refused at authorization — before consent and
     * before any `tool.executing` listener. The callable is guarded as well, as a second line.
     *
     * @return PermissionGuardedHandler|null null when withheld
     */
    private function guarded(McpToolModel $model, DIContainerInterface $container, ?\Milpa\ToolRuntime\PolicyGate $gate): ?PermissionGuardedHandler
    {
        $operation = $model->operation;
        if ($operation === null) {
            $this->withhold($model->name, \sprintf(
                'Tool «%s» requires the permission «%s» but carries no operation to judge it against; it is not served over MCP.',
                $model->name,
                $model->permission,
            ), $container);

            return null;
        }

        $policy = $container->has(OperationMcpPolicy::class) ? $container->get(OperationMcpPolicy::class) : null;
        if (!$policy instanceof OperationMcpPolicy) {
            $this->withhold($model->name, \sprintf(
                'Operation «%s» requires the permission «%s» and this host wired no %s; it is not served over MCP. '
                . 'Register a policy that resolves permissions, or type the operation by scopes.',
                $operation->name,
                $model->permission,
                OperationMcpPolicy::class,
            ), $container);

            return null;
        }

        if ($gate !== null) {
            $installed = $gate->getCallPolicy();
            if (!$installed instanceof PermissionCallPolicy) {
                $installed = new PermissionCallPolicy($policy, $installed);
                $gate->setCallPolicy($installed);
            }
            $installed->guard($model->name, $operation);
        }

        return new PermissionGuardedHandler(
            $policy,
            $operation,
            new OperationToolHandler(new OperationRunner($container, $this->dispatcher), $operation),
        );
    }

    private function withhold(string $tool, string $reason, DIContainerInterface $container): void
    {
        $this->withheld[$tool] = $reason;

        // A fresh house registers no logger, so without this fallback the warning reached nobody and
        // the withholding was silent after all. `error_log()` is the SAPI's own log — STDERR under
        // `coa mcp`, which is where a stdio server may speak (STDOUT belongs to the protocol).
        $logger = $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null;
        if ($logger instanceof LoggerInterface) {
            $logger->warning($reason);
        } else {
            error_log($reason);
        }
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
     * El callable que el registry va a invocar — y que pasa por {@see OperationRunner}, como las otras
     * tres superficies.
     *
     * Esta superficie YA tenía ganchos: `tool.executing` y `tool.executed` los emite el registry de
     * `milpa/tool-runtime`, y son de una HERRAMIENTA. Los del runner son de una OPERACIÓN, y por eso
     * conviven en vez de sustituirse: un listener que audita operaciones las ve por las cuatro
     * superficies, y uno que audita herramientas sigue viendo lo que el registry sirve —incluidas las
     * que un plugin registró con `#[Tool]` y nunca fueron una operación.
     *
     * @return callable(array<string, mixed>): mixed
     */
    private function callableFrom(McpToolModel $model, DIContainerInterface $container): callable
    {
        $operacion = $model->operation;
        if ($operacion !== null) {
            $runner = new OperationRunner($container, $this->dispatcher);

            return new OperationToolHandler($runner, $operacion);
        }

        // Un modelo armado a mano, sin la operación de la que salió: se ejecuta como antes. No hay
        // nada que emitir sobre una operación que no se tiene.
        $handler = $model->handler;
        if (\is_callable($handler)) {
            return $handler;
        }

        [$clase, $metodo] = $handler;

        return static fn (array $args): mixed => $container->get($clase)->{$metodo}($args);
    }
}

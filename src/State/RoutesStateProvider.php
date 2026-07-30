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

namespace Milpa\Console\State;

use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;

/**
 * El `SectionStateProvider` de la sección "Sistema": mapea las rutas de la tabla canónica
 * ({@see \Milpa\Console\Contracts\RouteTableSource::routes()}) a filas de display
 * {método, ruta, nombre, handler}, ordenadas por path. Es un MAPPER PURO — recibe `list<Route>`
 * por constructor; el load (que necesita el container, donde el assembler ya está registrado
 * como instancia única) lo hace el {@see \Milpa\Admin\Controllers\SystemController},
 * así el mapeo se prueba con fixtures sin infra.
 */
final class RoutesStateProvider implements SectionStateProvider
{
    /** @param list<Route> $routes el snapshot que el host publicó por su RouteTableSource */
    public function __construct(
        private readonly array $routes,
    ) {
    }

    /**
     * Las rutas como filas de display, listas para pintar.
     *
     * @return array{routes: list<array{method:string,path:string,name:string,handler:string}>}
     */
    public function state(): array
    {
        $rows = [];
        foreach ($this->routes as $route) {
            $handler = $route->handler;
            $rows[] = [
                'method' => implode('|', array_map(static fn ($m): string => $m->value, $route->methods)),
                'path' => $route->path,
                'name' => $route->name ?? '-',
                'handler' => $handler === null ? '-' : self::shortHandler($handler),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return ['routes' => $rows];
    }

    /**
     * Clase corta del controller (basename tras el último `\`) + `::` + método — el mismo formato
     * que la legacy `Routes` mostraba antes del repunte al `RouteTableAssembler` (que trae la FQCN
     * completa en `HandlerReference->controller`). Restaura la salida observable previa de la
     * tabla "Sistema" (Ola 4d.3a — corrección de regresión).
     */
    private static function shortHandler(HandlerReference $handler): string
    {
        $parts = explode('\\', $handler->controller);

        return end($parts) . '::' . $handler->method;
    }
}

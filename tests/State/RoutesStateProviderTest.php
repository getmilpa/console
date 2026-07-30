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

namespace Milpa\Console\Tests\State;

use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
use Milpa\Console\State\SectionStateProvider;
use Milpa\Console\State\RoutesStateProvider;
use PHPUnit\Framework\TestCase;

/**
 * `RoutesStateProvider` mapea las rutas de la tabla canónica ({@see \Milpa\Console\Contracts\RouteTableSource::routes()},
 * Ola 4d.3a) a filas de display {método,ruta,nombre,handler}, ordenadas por path. Es un mapper
 * PURO: recibe `list<Route>` por constructor, no carga nada — el load (que necesita el container
 * con el assembler ya registrado) vive en el SystemController.
 */
final class RoutesStateProviderTest extends TestCase
{
    public function test_is_an_admin_section_state_provider(): void
    {
        self::assertInstanceOf(SectionStateProvider::class, new RoutesStateProvider([]));
    }

    public function test_maps_each_route_to_a_display_row(): void
    {
        $route = (new Route('/milpa/admin/settings', HttpMethod::GET, 'milpa_admin_settings_show'))
            ->withHandler(HandlerReference::method('Milpa\\Plugins\\MilpaAdminPlugin\\Controllers\\SettingsController', 'show'));

        $rows = (new RoutesStateProvider([$route]))->state()['routes'];

        self::assertCount(1, $rows);
        self::assertSame('GET', $rows[0]['method']);
        self::assertSame('/milpa/admin/settings', $rows[0]['path']);
        self::assertSame('milpa_admin_settings_show', $rows[0]['name']);
        self::assertSame('SettingsController::show', $rows[0]['handler']);
    }

    public function test_joins_multiple_methods_and_defaults_a_missing_name(): void
    {
        $route = (new Route('/api/resource', [HttpMethod::GET, HttpMethod::POST]))
            ->withHandler(HandlerReference::method('App\\ResourceController', 'handle'));

        $rows = (new RoutesStateProvider([$route]))->state()['routes'];

        self::assertSame('GET|POST', $rows[0]['method']);
        self::assertSame('-', $rows[0]['name']);
    }

    public function test_unbound_route_shows_a_dash_for_the_handler(): void
    {
        $route = new Route('/pending', HttpMethod::GET, 'pending_route');

        $rows = (new RoutesStateProvider([$route]))->state()['routes'];

        self::assertSame('-', $rows[0]['handler']);
    }

    public function test_orders_rows_by_path(): void
    {
        $rows = (new RoutesStateProvider([
            new Route('/zebra', HttpMethod::GET),
            new Route('/alpha', HttpMethod::GET),
        ]))->state()['routes'];

        self::assertSame(['/alpha', '/zebra'], array_column($rows, 'path'));
    }
}

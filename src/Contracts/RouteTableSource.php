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

namespace Milpa\Console\Contracts;

use Milpa\Http\Routing\Route;

/**
 * De dónde sale la tabla de rutas que la sección "Sistema" muestra.
 *
 * Cada host arma su tabla a su manera: uno escanea atributos en directorios de controllers, otro
 * la declara a mano, otro la compila y la cachea. El panel no tiene por qué saber cuál — sólo
 * necesita poder preguntar "¿qué rutas tiene esta app?".
 *
 * Sin este puerto, la sección quedaba amarrada al ensamblador concreto de un host, que es la
 * razón por la que este panel no era un paquete: dos clases del host adentro y el resto ya era
 * portable.
 *
 * Un host que no lo registre simplemente no ve la sección Sistema; todo lo demás del panel sigue
 * funcionando.
 */
interface RouteTableSource
{
    /**
     * La tabla completa de rutas de la app, cada una ya atada a su handler.
     *
     * @return list<Route>
     */
    public function routes(): array;
}

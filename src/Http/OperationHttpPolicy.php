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

namespace Milpa\Console\Http;

use Milpa\Command\Operation;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Decide si esta petición puede correr esta operación.
 *
 * ── POR QUÉ ES UN CONTRATO ──────────────────────────────────────────────────────────────────────
 *
 * Porque quien sabe quién llama es el host, no el proyector. La implementación real de esta política
 * usa `milpa/auth` —contexto verificado, scopes, permisos— y meterla aquí arrastraría la identidad al
 * piso mínimo del framework: un paquete que sólo quiere exponer operaciones por HTTP tendría que
 * instalar un sistema de autenticación para lograrlo.
 *
 * Separarlas tiene una consecuencia medible y no sólo argumentable: las 37 referencias a `Milpa\Auth`
 * que tenía el proyector viven todas del otro lado de esta interfaz. `milpa/admin` publica la
 * implementación con auth; un host puede escribir la suya.
 *
 * ── NO SABER NO ES PERMITIR ─────────────────────────────────────────────────────────────────────
 *
 * Al revés que otros contratos opcionales de la familia, la ausencia de política NO deja pasar. Una
 * operación que declara scopes y corre sin nadie que los revise es exactamente el agujero que esta
 * capa vino a cerrar, así que {@see HttpProjector} se niega —con un 500, no un 403— cuando el host
 * declaró algo protegido y no cableó con qué protegerlo. La culpa es del servidor y la respuesta lo
 * dice; un 4xx culparía a quien llamó, que no hizo nada mal.
 */
interface OperationHttpPolicy
{
    /**
     * `null` significa adelante; una respuesta ES la negativa, ya formada (401 o 403).
     *
     * Devuelve la respuesta en vez de lanzar porque una negativa autorizada NO es un error: es el
     * resultado correcto de preguntar. Lo que sí lanza esta capa es el caso en que no se puede ni
     * preguntar —el host no cableó cadena de autenticación—, que es un defecto de configuración.
     *
     * @throws \RuntimeException cuando la operación exige identidad y el host no cableó ninguna
     */
    public function enforce(Operation $op, ServerRequestInterface $request): ?ResponseInterface;
}

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

namespace Milpa\Console\Rendering;

use Milpa\Console\Model\CliCommandModel;

/**
 * Materializa lo que la superficie `cli` proyectó: convierte un modelo —y lo que salió de correrlo—
 * en las líneas que una terminal escribe.
 *
 * Es la mitad que ADR-0035 nombra y que P13 no construyó. El projector produce
 * {@see CliCommandModel} y ahí se detiene, porque un projector nunca produce representación física;
 * quien la produce es esto. Hasta que existió, la segunda cláusula del ADR —*toda superficie debe
 * poder cambiar de renderer sin modificar su projector*— era literalmente incomprobable: los tres
 * modelos de la familia tenían exactamente un consumidor cada uno, el projector que los había
 * creado, y no había renderer que cambiar.
 *
 * ── POR QUÉ NO HAY UN CONTRATO BASE COMPARTIDO ──────────────────────────────────────────────────
 *
 * Podría existir un `SurfaceRenderer` en `milpa/command`, simétrico a `SurfaceProjector`. No existe
 * a propósito. Un projector puede compartir contrato porque todos devuelven un `SurfaceModel`, que
 * es un tipo real; un renderer devuelve la representación FÍSICA de su superficie —líneas de texto
 * aquí, un descriptor JSON en `mcp`, un árbol de widgets en `tui`— y esas no tienen tipo común. Un
 * contrato base sólo podría tiparlas como `mixed`, y un contrato que no nombra nada es exactamente
 * la falla que la cláusula 3 de ADR-0035 documenta: cuando `SurfaceProjector` declinó fijar su
 * método de proyección, sus tres implementaciones hicieron tres cosas incompatibles.
 *
 * ── LOS TRES MÉTODOS SON TRES MOMENTOS ──────────────────────────────────────────────────────────
 *
 * `describe()` corre ANTES de ejecutar y no necesita haber ejecutado: es la ayuda, el
 * autocompletado, el listado. `present()` y `presentError()` corren DESPUÉS. Separarlos es lo que
 * permite pedir la descripción de una operación sin causarla — la misma razón por la que el
 * projector es puro.
 *
 * `presentError()` no es un lujo: sin él, cambiar a un renderer de máquina dejaría los aciertos en
 * JSON y las fallas en texto con viñeta, o sea una salida que ningún consumidor puede parsear
 * completa. Un renderer que sólo sabe pintar el camino feliz no es intercambiable.
 */
interface CliRenderer
{
    /**
     * Las líneas con que esta terminal DESCRIBE la operación, sin correrla.
     *
     * @return list<string>
     */
    public function describe(CliCommandModel $model): array;

    /**
     * Las líneas con que esta terminal presenta lo que la operación devolvió.
     *
     * Recibe `mixed` porque un handler devuelve lo que su dominio tiene que decir —un arreglo, un
     * texto, nada— y forzar una forma común obligaría a cada operación a saber cómo se pinta, que es
     * justo la dependencia que este seam elimina.
     *
     * `$ok` es el VEREDICTO de la invocación, ya resuelto por quien ejecutó, y llega aparte porque
     * un renderer no puede deducirlo: tendría que interpretar la estructura del dominio, que es
     * justo lo que no le toca. Sin él, una envoltura de máquina afirmaría `ok: true` alrededor de un
     * resultado que dice `ok: false`, o sea dos afirmaciones contradictorias en un mismo documento.
     *
     * Es distinto de {@see self::presentError()}: aquí la operación SÍ produjo un resultado y el
     * veredicto es negativo; allá no llegó a producir ninguno.
     *
     * @return list<string>
     */
    public function present(mixed $result, bool $ok = true): array;

    /**
     * Las líneas con que esta terminal presenta una falla ya explicada.
     *
     * Recibe el mensaje y no la excepción: decidir QUÉ falló es de quien ejecuta; decidir cómo se
     * ve, de esto.
     *
     * @return list<string>
     */
    public function presentError(string $message): array;
}

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
 * El renderer para un programa: una línea de JSON por respuesta.
 *
 * Es el hermano de {@see PlainTextCliRenderer} y existe para ser INTERCAMBIADO con él. Ése es su
 * trabajo principal: dos renderers para la misma superficie, alimentados por el mismo projector sin
 * tocarlo, es lo que vuelve falsificable la segunda cláusula de ADR-0035. Un contrato con una sola
 * implementación no prueba que se pueda cambiar de implementación.
 *
 * ── LO QUE RETIRA ───────────────────────────────────────────────────────────────────────────────
 *
 * La bandera `--json`. Hoy varios comandos del host la traen y cada uno decide por su cuenta qué
 * forma tiene su JSON, dentro del mismo método que también sabe pintar la versión humana. Eso es un
 * comando que conoce a sus dos renderers, o sea la dependencia invertida. Con esto, la operación
 * devuelve estructura y no sabe que existen formatos; la elección de formato es de quien materializa.
 *
 * El acierto y la falla comparten envoltura (`ok`) a propósito: un consumidor que tiene que
 * distinguir el éxito del error por la FORMA del documento —a veces objeto, a veces texto— acaba
 * escribiendo el parser dos veces.
 */
final class JsonCliRenderer implements CliRenderer
{
    /**
     * El modelo tal cual, en una línea: es la forma que ya tiene y no hay nada que traducir.
     *
     * @return list<string>
     */
    public function describe(CliCommandModel $model): array
    {
        return [$this->codificar($model->toArray())];
    }

    /**
     * El resultado envuelto en `ok: true`, sin aplanarlo: lo que un programa quiere de vuelta es lo
     * que el handler devolvió, con la misma estructura y sin decisiones de presentación encima.
     *
     * @return list<string>
     */
    public function present(mixed $result, bool $ok = true): array
    {
        return [$this->codificar(['ok' => $ok, 'result' => $result])];
    }

    /**
     * La falla con la MISMA envoltura que el acierto, cambiando sólo `ok`.
     *
     * @return list<string>
     */
    public function presentError(string $message): array
    {
        return [$this->codificar(['ok' => false, 'error' => $message])];
    }

    /**
     * Una línea, siempre.
     *
     * Sin `JSON_PRETTY_PRINT`: la salida de un comando es un flujo, y un documento multilínea obliga
     * al consumidor a saber dónde termina. Con una línea por respuesta se puede leer por líneas, que
     * es como una terminal entrega las cosas.
     *
     * Un valor que no se puede codificar no se calla: `json_encode` devuelve `false` y eso se
     * convierte en un error explícito, porque un documento vacío sería indistinguible de un
     * resultado vacío legítimo.
     *
     * @param array<string, mixed> $carga
     */
    private function codificar(array $carga): string
    {
        $json = json_encode($carga, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return (string) json_encode([
                'ok' => false,
                'error' => 'the result could not be encoded as JSON: ' . json_last_error_msg(),
            ]);
        }

        return $json;
    }
}

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
 * El renderer para una persona: texto plano, indentado, sin marcas de ningún formato.
 *
 * No emite secuencias ANSI ni etiquetas de estilo. La decisión es del mismo tipo que la que separa
 * projector de renderer, un piso más abajo: colorear es de quien ESCRIBE al descriptor —sabe si hay
 * TTY, si el usuario pidió `--no-ansi`, si la salida está redirigida— y este renderer no puede
 * saberlo. Devuelve líneas; quien las escriba decide cómo se ven.
 */
final class PlainTextCliRenderer implements CliRenderer
{
    /**
     * Nombre, descripción y las banderas que el modelo declara, con su tipo y si son obligatorias.
     *
     * @return list<string>
     */
    public function describe(CliCommandModel $model): array
    {
        $lineas = [$model->name, '  ' . $model->description];

        if ($model->flags !== []) {
            $lineas[] = '';
            $lineas[] = '  Opciones:';
            // El modelo ya garantiza la forma de cada bandera (`array{type: string, required: bool}`),
            // así que aquí no hay nada que defender: revalidarlo sería desconfiar del tipo que el
            // projector ya fijó, y PHPStan lo señala como código muerto — con razón.
            foreach ($model->flags as $nombre => $definicion) {
                $lineas[] = rtrim(\sprintf(
                    '    --%-24s %-14s %s',
                    $nombre . '=<' . $definicion['type'] . '>',
                    $definicion['required'] ? '(obligatoria)' : '(opcional)',
                    $definicion['description'],
                ));
            }
        }

        if ($model->needsSignature) {
            $lineas[] = '';
            $lineas[] = '  Muta y exige autorización: córrela con --sign.';
        }

        return $lineas;
    }

    /**
     * Presenta el resultado según su forma, que es lo único que se puede saber de él aquí.
     *
     * Un arreglo se recorre como pares clave/valor porque es la forma en que un handler devuelve un
     * informe; un escalar se imprime; `null` no imprime nada, porque una operación que no devolvió
     * nada no tiene nada que decir y una línea vacía mentiría diciendo que sí.
     *
     * @return list<string>
     */
    public function present(mixed $result, bool $ok = true): array
    {
        // El veredicto no se pinta aparte: un resultado que fue negativo ya lo dice con sus propias
        // palabras, y una línea extra «falló» encima sería el mismo hecho contado dos veces.
        unset($ok);

        if ($result === null) {
            return [];
        }
        if (\is_bool($result)) {
            return [$result ? 'ok' : 'no'];
        }
        if (\is_scalar($result)) {
            return [(string) $result];
        }
        if (\is_array($result)) {
            return $this->pares($result, '');
        }

        return [\get_debug_type($result)];
    }

    /**
     * Una línea con la marca que una persona reconoce de un vistazo como «esto no salió».
     *
     * @return list<string>
     */
    public function presentError(string $message): array
    {
        return ['✗ ' . $message];
    }

    /**
     * Aplana un arreglo a líneas `clave: valor`, descendiendo por los anidados.
     *
     * Una lista se numera y un mapa se nombra, que es la diferencia que un lector necesita ver: en
     * una lista importa cuántos hay, en un mapa importa cómo se llaman.
     *
     * @param array<array-key, mixed> $datos
     *
     * @return list<string>
     */
    private function pares(array $datos, string $sangria): array
    {
        $lineas = [];
        $esLista = array_is_list($datos);

        foreach ($datos as $clave => $valor) {
            $etiqueta = $esLista ? '-' : ((string) $clave . ':');

            if (\is_array($valor)) {
                $tabla = $this->tabla($valor, $sangria . '  ');
                if ($tabla !== null) {
                    $lineas[] = $sangria . $etiqueta;
                    foreach ($tabla as $linea) {
                        $lineas[] = $linea;
                    }
                    continue;
                }

                $lineas[] = $sangria . $etiqueta;
                foreach ($this->pares($valor, $sangria . '  ') as $linea) {
                    $lineas[] = $linea;
                }
                continue;
            }

            $lineas[] = $sangria . $etiqueta . ' ' . $this->escalar($valor);
        }

        return $lineas;
    }

    /**
     * Una lista de registros con las MISMAS llaves escalares se pinta como tabla alineada.
     *
     * No es un caso especial de ningún comando: es una FORMA. Un diagnóstico que devuelve
     * `[{name, ok, summary}, …]` describe una tabla, y aplanarlo a pares clave/valor convierte doce
     * líneas escaneables en cuarenta que no se pueden comparar de un vistazo. Eso se midió al
     * convertir `coa:doctor`, y sin esto la migración a átomos habría cambiado una tabla hecha a
     * mano por una lista peor en cada comando que tuviera una.
     *
     * Las condiciones son estrictas a propósito. Basta un registro con otra llave, o con un valor
     * anidado, para que no sea tabla — y entonces se cae al recorrido normal, que muestra todo. Una
     * tabla que oculta columnas porque los renglones no coincidían sería peor que no tenerla.
     *
     * @param array<array-key, mixed> $datos
     *
     * @return list<string>|null null cuando esto no es una tabla
     */
    private function tabla(array $datos, string $sangria): ?array
    {
        if ($datos === [] || !array_is_list($datos)) {
            return null;
        }

        $columnas = null;
        foreach ($datos as $registro) {
            // `array_is_list([])` es true, así que un registro vacío ya queda fuera aquí.
            if (!\is_array($registro) || array_is_list($registro)) {
                return null;
            }
            foreach ($registro as $valor) {
                if ($valor !== null && !\is_scalar($valor)) {
                    return null;
                }
            }
            $llaves = array_map(strval(...), array_keys($registro));
            if ($columnas === null) {
                $columnas = $llaves;
                continue;
            }
            if ($columnas !== $llaves) {
                return null;
            }
        }

        // `$columnas` está poblada: la lista no es vacía y cada vuelta o la asigna o sale.
        $anchos = [];
        foreach ($columnas as $columna) {
            $anchos[$columna] = mb_strlen($columna);
        }
        /** @var array<array-key, array<array-key, mixed>> $datos */
        foreach ($datos as $registro) {
            foreach ($columnas as $columna) {
                $anchos[$columna] = max($anchos[$columna], mb_strlen($this->escalar($registro[$columna])));
            }
        }

        $lineas = [$sangria . rtrim($this->renglon($columnas, $anchos, static fn (string $c): string => $c))];
        foreach ($datos as $registro) {
            $lineas[] = $sangria . rtrim($this->renglon(
                $columnas,
                $anchos,
                fn (string $c): string => $this->escalar($registro[$c]),
            ));
        }

        return $lineas;
    }

    /**
     * @param list<string>             $columnas
     * @param array<string, int>       $anchos
     * @param callable(string): string $celda
     */
    private function renglon(array $columnas, array $anchos, callable $celda): string
    {
        $partes = [];
        foreach ($columnas as $columna) {
            $texto = $celda($columna);
            $partes[] = $texto . str_repeat(' ', max(0, $anchos[$columna] - mb_strlen($texto)));
        }

        return implode('  ', $partes);
    }

    /** Un escalar en la forma más corta que sigue siendo inequívoca. */
    private function escalar(mixed $valor): string
    {
        if ($valor === null) {
            return '—';
        }
        if (\is_bool($valor)) {
            return $valor ? 'sí' : 'no';
        }
        if (\is_scalar($valor)) {
            return (string) $valor;
        }

        return \get_debug_type($valor);
    }
}

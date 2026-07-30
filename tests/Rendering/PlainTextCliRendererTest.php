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

namespace Milpa\Console\Tests\Rendering;

use Milpa\Console\Model\CliCommandModel;
use Milpa\Console\Rendering\JsonCliRenderer;
use Milpa\Console\Rendering\PlainTextCliRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Cómo se lee un resultado, forma por forma.
 *
 * {@see \Milpa\Console\Tests\Rendering\RendererSwapTest} prueba que se puede CAMBIAR de renderer;
 * esto prueba qué produce cada uno. Son preguntas distintas y la primera pasaba sin la segunda —
 * el piso de cobertura del CI publicado lo dijo antes que nadie.
 */
final class PlainTextCliRendererTest extends TestCase
{
    private function renderer(): PlainTextCliRenderer
    {
        return new PlainTextCliRenderer();
    }

    /**
     * Cada forma escalar se dice como se lee, y `null` NO imprime nada.
     *
     * Una línea vacía afirmaría que la operación dijo algo. No dijo nada, y eso también es una
     * respuesta — la que un shell encadenando comandos necesita distinguir.
     */
    public function testEachScalarShapeReadsAsItself(): void
    {
        self::assertSame([], $this->renderer()->present(null));
        self::assertSame(['ok'], $this->renderer()->present(true));
        self::assertSame(['no'], $this->renderer()->present(false));
        self::assertSame(['42'], $this->renderer()->present(42));
        self::assertSame(['hola'], $this->renderer()->present('hola'));
        self::assertSame(['stdClass'], $this->renderer()->present(new \stdClass()));
    }

    /**
     * Un mapa se nombra y una lista se marca, porque no se leen igual.
     *
     * En un mapa importa cómo se llama cada cosa; en una lista importa cuántas hay.
     */
    public function testAMapIsNamedAndAListIsMarked(): void
    {
        self::assertSame(
            ['a: 1', 'b:', '  - x', '  - y'],
            $this->renderer()->present(['a' => 1, 'b' => ['x', 'y']]),
        );
    }

    /** Un valor ausente se dice `—`, que es distinto de una cadena vacía. */
    public function testAnAbsentValueIsSaidAndNotOmitted(): void
    {
        self::assertSame(['a: —', 'b: sí'], $this->renderer()->present(['a' => null, 'b' => true]));
    }

    /**
     * Una lista de registros con las MISMAS llaves se alinea como tabla.
     *
     * No es un caso especial de ningún comando: es una FORMA. Se agregó al convertir un diagnóstico
     * cuyos doce renglones escaneables se habían vuelto cuarenta de lista anidada.
     */
    public function testUniformRecordsBecomeAnAlignedTable(): void
    {
        $lineas = $this->renderer()->present(['checks' => [
            ['name' => 'uno', 'ok' => true],
            ['name' => 'dos-mas-largo', 'ok' => false],
        ]]);

        self::assertSame('checks:', $lineas[0]);
        self::assertSame('  name           ok', $lineas[1]);
        self::assertSame('  uno            sí', $lineas[2]);
        self::assertSame('  dos-mas-largo  no', $lineas[3]);
    }

    /**
     * Basta un registro distinto para que NO sea tabla.
     *
     * Las condiciones son estrictas a propósito: una tabla que esconde columnas porque los renglones
     * no coincidían sería peor que no tenerla. Al no calificar, se cae al recorrido normal, que
     * muestra todo.
     */
    public function testOneMismatchedRecordFallsBackToShowingEverything(): void
    {
        $lineas = $this->renderer()->present(['filas' => [
            ['a' => 1, 'b' => 2],
            ['a' => 3, 'c' => 4],
        ]]);

        self::assertContains('    c: 4', $lineas, 'la llave que no casaba sigue visible');
    }

    /** Una lista de registros con un valor anidado tampoco es tabla. */
    public function testARecordWithANestedValueIsNotATable(): void
    {
        $lineas = $this->renderer()->present(['filas' => [['a' => ['x']], ['a' => ['y']]]]);

        self::assertNotSame('  a', $lineas[1] ?? '', 'no se pintó como cabecera de tabla');
    }

    /** La descripción del comando incluye sus banderas, su tipo y si son obligatorias. */
    public function testDescribeListsTheFlagsWithTheirHelp(): void
    {
        $lineas = $this->renderer()->describe(new CliCommandModel(
            'validar',
            'Valida algo',
            [
                'target' => ['type' => 'string', 'required' => true, 'description' => 'Qué validar'],
                'json' => ['type' => 'boolean', 'required' => false, 'description' => ''],
            ],
        ));

        $texto = implode("\n", $lineas);
        self::assertStringContainsString('validar', $texto);
        self::assertStringContainsString('--target=<string>', $texto);
        self::assertStringContainsString('(obligatoria)', $texto);
        self::assertStringContainsString('Qué validar', $texto);
        self::assertStringContainsString('(opcional)', $texto);
    }

    /**
     * Una operación que exige firma lo ANUNCIA en su ayuda.
     *
     * Enterarse al ejecutar es enterarse tarde: quien lee la ayuda está decidiendo si tiene la
     * tarjeta a mano.
     */
    public function testDescribeAnnouncesTheSignatureUpFront(): void
    {
        $lineas = $this->renderer()->describe(new CliCommandModel('borrar', 'Borra', [], needsSignature: true));

        self::assertStringContainsString('--sign', implode("\n", $lineas));
    }

    /** Sin banderas no se imprime un encabezado de opciones vacío. */
    public function testAnOperationWithNoInputsHasNoOptionsSection(): void
    {
        $lineas = $this->renderer()->describe(new CliCommandModel('doctor', 'Diagnostica', []));

        self::assertSame(['doctor', '  Diagnostica'], $lineas);
    }

    /**
     * Lo que no se puede codificar se DICE, no se calla.
     *
     * Un documento vacío sería indistinguible de un resultado vacío legítimo, y quien lo consuma no
     * tendría cómo saber cuál de los dos le tocó.
     */
    public function testTheMachineRendererSaysWhatItCannotEncode(): void
    {
        $recurso = fopen('php://memory', 'r');
        $linea = (new JsonCliRenderer())->present(['x' => $recurso])[0];
        fclose($recurso);

        /** @var array{ok: bool, error: string} $json */
        $json = json_decode($linea, true);
        self::assertFalse($json['ok']);
        self::assertStringContainsString('JSON', $json['error']);
    }
}

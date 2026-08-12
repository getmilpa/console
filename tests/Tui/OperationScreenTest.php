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

namespace Milpa\Console\Tests\Tui;

use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Console\Tui\OperationScreen;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Una operación que se LLENA y se CORRE en la terminal — la mitad que faltaba de la superficie tui.
 *
 * `TuiProjector` ya producía el árbol y ahí se detenía: nadie capturaba lo tecleado ni llamaba al
 * handler, así que `tui` existía como proyección y no como superficie. Estas pruebas corren sin
 * terminal porque la pantalla se puede renderizar como texto — la compuerta de si hay TTY es del
 * comando, no de aquí (ADR-0025).
 */
final class OperationScreenTest extends TestCase
{
    private function container(): ContainerInterface
    {
        return new class () implements ContainerInterface {
            public function get(string $id): mixed
            {
                return null;
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }

    private function operacion(bool $muta = false, bool $firma = false): Operation
    {
        return new Operation(
            name: 'plugins_verify',
            description: 'Verifica el manifiesto',
            handler: static fn (array $i): array => ['ok' => true, 'visto' => $i['plugin'] ?? '(nada)'],
            inputSchema: [
                'type' => 'object',
                'properties' => ['plugin' => ['type' => 'string'], 'strict' => ['type' => 'boolean']],
                'required' => ['plugin'],
            ],
            mutating: $muta,
            requiresConfirmation: $firma,

            // The profile FOLLOWS the flag rather than guessing it: Operation refuses a probe that
            // declares mutating: true beside mutation: none, and it is right to — a consumer cannot
            // be asked which of the two lies.
            effects: new EffectProfile(
                mutation: $muta ? Mutation::Persistent : Mutation::None,
                externality: Externality::None,
                reversibility: Reversibility::Guaranteed,
                authority: Authority::Read,
                subject: $muta ? Subject::Data : Subject::None,
                rollbackContract: 'test probe: nothing leaves this process',
            ),
        );
    }

    private function pantalla(?Operation $op = null): OperationScreen
    {
        return new OperationScreen($op ?? $this->operacion(), $this->container(), 60, 16, false);
    }

    private function teclear(OperationScreen $pantalla, string $texto): void
    {
        foreach (preg_split('//u', $texto, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $caracter) {
            $pantalla->press($caracter);
        }
    }

    /**
     * Se teclea en el campo enfocado y Enter corre la operación con eso.
     *
     * Es la prueba de que la superficie existe: antes, lo tecleado no llegaba a ningún lado.
     */
    public function testTypingIntoAFieldAndPressingEnterRunsTheOperation(): void
    {
        $pantalla = $this->pantalla();
        $this->teclear($pantalla, 'MarketingPlugin');
        $pantalla->press('enter');

        self::assertSame('MarketingPlugin', $pantalla->values()['plugin']);
        self::assertSame('corrida', $pantalla->state());
        self::assertTrue($pantalla->ok());
        self::assertStringContainsString('MarketingPlugin', implode(' ', $pantalla->output()));
    }

    /**
     * Las MAYÚSCULAS llegan.
     *
     * `dispatchKey()` entrega el nombre canónico de la tecla, y para un carácter suelto viene en
     * minúscula porque un atajo declarado `l` tiene que casar con `L`. Eso es correcto para un atajo
     * y volvía imposible teclear el nombre de un plugin. La pantalla lee el crudo.
     */
    public function testUppercaseSurvives(): void
    {
        $pantalla = $this->pantalla();
        $this->teclear($pantalla, 'ABC');

        self::assertSame('ABC', $pantalla->values()['plugin']);
    }

    /**
     * Y la letra `q` también.
     *
     * El tier trae `q` entre sus teclas de salida —lo que un dashboard quiere— así que un campo de
     * texto se cerraba al teclearla. No se puede escribir «query» ni «plugin» sin ella.
     */
    public function testTheLetterQIsTypedAndDoesNotCloseTheScreen(): void
    {
        $pantalla = $this->pantalla();
        $this->teclear($pantalla, 'query');

        self::assertSame('query', $pantalla->values()['plugin']);
    }

    /** Backspace borra el último carácter, no el campo. */
    public function testBackspaceDeletesOneCharacter(): void
    {
        $pantalla = $this->pantalla();
        $this->teclear($pantalla, 'abc');
        $pantalla->press('backspace');

        self::assertSame('ab', $pantalla->values()['plugin']);
    }

    /**
     * Lo que exige FIRMA no se corre desde aquí, y la negativa trae la línea que sí funciona.
     *
     * Una firma nombra esta llamada y se produce con una llave que vive fuera de esta pantalla. Un
     * «¿seguro?» consentiría en abstracto, que es justo lo que la firma reemplazó.
     */
    public function testAnOperationThatDemandsASignatureIsNotRunHereAndSaysWhereItIs(): void
    {
        $pantalla = $this->pantalla($this->operacion(muta: true, firma: true));
        $this->teclear($pantalla, 'MiPlugin');
        $pantalla->press('enter');

        self::assertSame('firma-requerida', $pantalla->state());
        self::assertFalse($pantalla->ok());
        $salida = implode("\n", $pantalla->output());
        self::assertStringContainsString('--sign', $salida);
        self::assertStringContainsString('MiPlugin', $salida, 'la línea trae lo que ya se tecleó');
        self::assertStringContainsString('coa plugins:verify', $salida);
    }

    /**
     * Un resultado con `ok: false` se reporta como fallo, no como éxito pintado.
     *
     * Es la misma convención de veredicto que el CLI usa para el código de salida: sin ella, una
     * pantalla que pinta un error como si todo hubiera ido bien miente con más autoridad que un
     * texto plano.
     */
    public function testAFalseVerdictIsReportedAsFailure(): void
    {
        $op = new Operation(
            name: 'algo',
            description: 'Algo',
            handler: static fn (array $i): array => ['ok' => false, 'error' => 'no se pudo'],
            inputSchema: ['type' => 'object', 'properties' => []],
            effects: new EffectProfile(
                mutation: Mutation::None,
                externality: Externality::None,
                reversibility: Reversibility::Guaranteed,
                authority: Authority::Read,
                subject: Subject::None,
                rollbackContract: 'test probe: nothing leaves this process',
            ),
        );
        $pantalla = new OperationScreen($op, $this->container(), 60, 12, false);
        $pantalla->press('enter');

        self::assertFalse($pantalla->ok());
        self::assertStringContainsString('no se pudo', implode(' ', $pantalla->output()));
    }

    /** Una entrada que el esquema rechaza se dice ahí mismo, sin llamar al handler. */
    public function testInputTheSchemaRejectsIsSaidWithoutRunning(): void
    {
        $corrio = false;
        $op = new Operation(
            name: 'estricta',
            description: 'Estricta',
            handler: static function (array $i) use (&$corrio): array {
                $corrio = true;

                return ['ok' => true];
            },
            inputSchema: [
                'type' => 'object',
                'properties' => ['cuantos' => ['type' => 'integer']],
                'required' => ['cuantos'],
            ],
            effects: new EffectProfile(
                mutation: Mutation::None,
                externality: Externality::None,
                reversibility: Reversibility::Guaranteed,
                authority: Authority::Read,
                subject: Subject::None,
                rollbackContract: 'test probe: nothing leaves this process',
            ),
        );
        $pantalla = new OperationScreen($op, $this->container(), 60, 12, false);
        $this->teclear($pantalla, 'no-es-un-numero');
        $pantalla->press('enter');

        self::assertFalse($pantalla->ok());
        self::assertFalse($corrio, 'una entrada inválida no puede haber llegado al handler');
    }

    /** Un handler que lanza se reporta con su mensaje, sin tumbar la pantalla. */
    public function testAThrowingHandlerIsReportedAndTheScreenSurvives(): void
    {
        $op = new Operation(
            name: 'truena',
            description: 'Truena',
            handler: static function (array $i): array {
                throw new \RuntimeException('se cayó la base');
            },
            inputSchema: ['type' => 'object', 'properties' => []],
            effects: new EffectProfile(
                mutation: Mutation::None,
                externality: Externality::None,
                reversibility: Reversibility::Guaranteed,
                authority: Authority::Read,
                subject: Subject::None,
                rollbackContract: 'test probe: nothing leaves this process',
            ),
        );
        $pantalla = new OperationScreen($op, $this->container(), 60, 12, false);
        $pantalla->press('enter');

        self::assertFalse($pantalla->ok());
        self::assertStringContainsString('se cayó la base', implode(' ', $pantalla->output()));
        self::assertNotSame('', $pantalla->render(), 'la pantalla sigue pintándose');
    }

    /** La pantalla dice qué campos hay, cuáles son obligatorios y si la operación cambia algo. */
    public function testTheScreenNamesItsFieldsAndWarnsWhenItMutates(): void
    {
        $texto = $this->pantalla($this->operacion(muta: true))->render();

        self::assertStringContainsString('plugin', $texto);
        self::assertStringContainsString('strict', $texto);
        self::assertStringContainsString('*', $texto, 'lo obligatorio se marca');
        self::assertStringContainsString('cambia algo', $texto);
    }
}

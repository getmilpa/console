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

use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Console\CliProjector;
use Milpa\Console\CliRunner;
use Milpa\Console\Rendering\JsonCliRenderer;
use Milpa\Console\Rendering\PlainTextCliRenderer;
use Milpa\Interfaces\Di\DIContainerInterface;
use PHPUnit\Framework\TestCase;

/**
 * El falsificador de la segunda cláusula de ADR-0035: *toda superficie debe poder cambiar de
 * renderer sin modificar su projector*.
 *
 * Hasta que existió `Rendering\`, esa cláusula era incomprobable — los tres modelos de la familia
 * tenían exactamente un consumidor cada uno, el projector que los creó, así que no había renderer
 * que cambiar. Estas pruebas la vuelven falsificable: si algún día materializar exigiera tocar
 * `CliProjector`, esta clase deja de compilar o deja de pasar.
 *
 * Cómo se refuta la cláusula, concretamente: el mismo `CliProjector`, la misma `Operation` y la
 * misma proyección alimentan dos renderers, y sólo cambia lo que sale.
 */
final class RendererSwapTest extends TestCase
{
    private DIContainerInterface $container;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = $this->createMock(DIContainerInterface::class);
    }

    /**
     * Una proyección, dos materializaciones — y el projector se instancia UNA vez para las dos, que
     * es lo que prueba que ninguno de los dos caminos lo tocó.
     */
    public function testOneProjectionMaterialisesTwoWays(): void
    {
        $op = new Operation(
            name: 'validate',
            description: 'Valida un manifiesto',
            handler: static fn (array $i): array => $i,
            inputSchema: [
                'type' => 'object',
                'properties' => ['target' => ['type' => 'string']],
                'required' => ['target'],
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

        $modelo = (new CliProjector())->project($op);

        $texto = (new PlainTextCliRenderer())->describe($modelo);
        $json = (new JsonCliRenderer())->describe($modelo);

        self::assertSame('validate', $texto[0]);
        self::assertContains('  Opciones:', $texto);
        self::assertStringContainsString('--target', implode("\n", $texto));

        self::assertCount(1, $json);
        /** @var array{surface: string, name: string, flags: array<string, mixed>} $decodificado */
        $decodificado = json_decode($json[0], true);
        self::assertSame('cli', $decodificado['surface']);
        self::assertSame('validate', $decodificado['name']);
        self::assertArrayHasKey('target', $decodificado['flags']);

        self::assertNotSame($texto, $json, 'dos renderers que producen lo mismo no prueban nada');
    }

    /**
     * El resultado también se materializa, y el runner no sabe de formatos: recibe el renderer.
     */
    public function testTheRunnerPresentsItsResultThroughWhicheverRendererItWasGiven(): void
    {
        $op = new Operation(
            name: 'report',
            description: 'Devuelve estructura',
            handler: static fn (array $i): array => ['ok' => true, 'checks' => ['manifest' => 'OK']],
            effects: new EffectProfile(
                mutation: Mutation::None,
                externality: Externality::None,
                reversibility: Reversibility::Guaranteed,
                authority: Authority::Read,
                subject: Subject::None,
                rollbackContract: 'test probe: nothing leaves this process',
            ),
        );

        self::assertSame(
            ['ok: sí', 'checks:', '  manifest: OK'],
            $this->correr($op, new PlainTextCliRenderer()),
        );

        self::assertSame(
            ['{"ok":true,"result":{"ok":true,"checks":{"manifest":"OK"}}}'],
            $this->correr($op, new JsonCliRenderer()),
        );
    }

    /**
     * La falla viaja por el mismo camino que el acierto.
     *
     * Sin esto, cambiar a un renderer de máquina dejaría los aciertos en JSON y las fallas en texto
     * con viñeta — una salida que ningún consumidor puede parsear completa, y el tipo de verde
     * parcial que este repo lleva sesiones cazando.
     */
    public function testFailuresTravelThroughTheRendererToo(): void
    {
        $op = new Operation(
            name: 'strict',
            description: 'Exige un entero',
            handler: static fn (array $i): array => $i,
            inputSchema: [
                'type' => 'object',
                'properties' => ['n' => ['type' => 'integer']],
                'required' => ['n'],
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

        $texto = $this->correr($op, new PlainTextCliRenderer(), ['--n=no-es-un-entero']);
        self::assertNotSame([], $texto);
        self::assertStringStartsWith('✗ ', $texto[0]);

        $json = $this->correr($op, new JsonCliRenderer(), ['--n=no-es-un-entero']);
        self::assertCount(1, $json);
        /** @var array{ok: bool, error: string} $decodificado */
        $decodificado = json_decode($json[0], true);
        self::assertFalse($decodificado['ok']);
        self::assertNotSame('', $decodificado['error']);
    }

    /**
     * `null` no imprime nada, y un entero sigue siendo código de salida.
     *
     * Las dos son convenciones que el runner tenía enterradas en condicionales y que ahora hay que
     * fijar, porque un renderer podría razonablemente pintar «null» y romper a quien encadena
     * comandos.
     */
    public function testNothingReturnedPrintsNothingAndAnIntStaysAnExitCode(): void
    {
        $callado = new Operation(
            'quiet',
            'No devuelve nada',
            static fn (array $i): null => null,
            effects: new EffectProfile(
                mutation: Mutation::None,
                externality: Externality::None,
                reversibility: Reversibility::Guaranteed,
                authority: Authority::Read,
                subject: Subject::None,
                rollbackContract: 'test probe: nothing leaves this process',
            ),
        );
        self::assertSame([], $this->correr($callado, new PlainTextCliRenderer()));

        $lines = [];
        $codigo = (new CliRunner(renderer: new PlainTextCliRenderer()))->run(
            new Operation(
                'coded',
                'Reporta por su cuenta',
                static fn (array $i): int => 3,
                effects: new EffectProfile(
                    mutation: Mutation::None,
                    externality: Externality::None,
                    reversibility: Reversibility::Guaranteed,
                    authority: Authority::Read,
                    subject: Subject::None,
                    rollbackContract: 'test probe: nothing leaves this process',
                ),
            ),
            [],
            $this->container,
            static function (string $l) use (&$lines): void {
                $lines[] = $l;
            },
        );

        self::assertSame(3, $codigo);
        self::assertSame([], $lines, 'un entero es un código de salida, no algo que pintar');
    }

    /**
     * Un `ok: false` en la raíz del resultado es un VEREDICTO, y sale por el código de salida.
     *
     * Antes se ignoraba, y el costo era silencioso: una operación de diagnóstico que reportaba
     * `ok: false` salía con código 0, así que un CI que la corría pasaba en verde sobre un objetivo
     * inválido. No es una convención inventada aquí — es la que la familia ya usaba en toda salida
     * `--json`; lo nuevo es que se honra.
     *
     * Un resultado SIN `ok` es un acierto: la mayoría de las operaciones devuelven datos y no
     * veredictos, y exigirles la llave las obligaría a saber que existe una terminal.
     */
    public function testAVerdictInTheResultBecomesTheExitCode(): void
    {
        $container = $this->container;
        $correr = static function (mixed $devuelve) use ($container): int {
            return (new CliRunner())->run(
                new Operation(
                    'check',
                    'Diagnostica',
                    static fn (array $i): mixed => $devuelve,
                    effects: new EffectProfile(
                        mutation: Mutation::None,
                        externality: Externality::None,
                        reversibility: Reversibility::Guaranteed,
                        authority: Authority::Read,
                        subject: Subject::None,
                        rollbackContract: 'test probe: nothing leaves this process',
                    ),
                ),
                [],
                $container,
                static fn (string $l) => null,
            );
        };

        self::assertSame(1, $correr(['ok' => false, 'findings' => ['roto']]));
        self::assertSame(0, $correr(['ok' => true]));
        self::assertSame(0, $correr(['plugins' => []]), 'sin veredicto declarado, no hay veredicto negativo');
        self::assertSame(0, $correr(['ok' => 'sí']), 'un `ok` que no es booleano no es un veredicto');
    }

    /** La envoltura de máquina AFIRMA lo mismo que el veredicto, o el documento se contradice. */
    public function testTheMachineEnvelopeAgreesWithTheVerdict(): void
    {
        $lines = $this->correr(
            new Operation(
                'check',
                'Diagnostica',
                static fn (array $i): array => ['ok' => false, 'why' => 'x'],
                effects: new EffectProfile(
                    mutation: Mutation::None,
                    externality: Externality::None,
                    reversibility: Reversibility::Guaranteed,
                    authority: Authority::Read,
                    subject: Subject::None,
                    rollbackContract: 'test probe: nothing leaves this process',
                ),
            ),
            new JsonCliRenderer(),
        );

        /** @var array{ok: bool, result: array{ok: bool}} $decodificado */
        $decodificado = json_decode($lines[0], true);
        self::assertFalse($decodificado['ok']);
        self::assertFalse($decodificado['result']['ok']);
    }

    /**
     * @param list<string> $argv
     *
     * @return list<string>
     */
    private function correr(Operation $op, PlainTextCliRenderer|JsonCliRenderer $renderer, array $argv = []): array
    {
        $lines = [];
        (new CliRunner(renderer: $renderer))->run($op, $argv, $this->container, static function (string $l) use (&$lines): void {
            $lines[] = $l;
        });

        return $lines;
    }
}

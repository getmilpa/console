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

namespace Milpa\Console\Tests;

use Milpa\Console\CliProjector;
use Milpa\Console\CliRunner;
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Interfaces\Di\DIContainerInterface;
use PHPUnit\Framework\TestCase;

final class CliProjectorTest extends TestCase
{
    private CliRunner $projector;
    private DIContainerInterface $container;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projector = new CliRunner();
        $this->container = $this->createMock(DIContainerInterface::class);
    }

    public function testDerivesTypedInputFromFlagsPerSchema(): void
    {
        $op = new Operation('create_post', 'Create', static fn (array $i): array => $i, inputSchema: [
            'type' => 'object',
            'properties' => ['title' => ['type' => 'string'], 'priority' => ['type' => 'integer']],
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

        $input = $this->projector->deriveInput($op, ['--title=Hi', '--priority=3']);

        self::assertSame(['title' => 'Hi', 'priority' => 3], $input);
    }

    /**
     * El resultado ya no se codifica aquí: se le entrega al renderer, y el default de una terminal
     * es texto para una persona.
     *
     * Esta prueba afirmaba `['{"got":{"n":42}}']` y no porque alguien hubiera elegido JSON, sino
     * porque `json_encode` era el único camino que existía para un resultado no-escalar. Elegir el
     * formato es de quien materializa; para pedir aquel JSON de vuelta está
     * {@see \Milpa\Console\Tests\Rendering\RendererSwapTest}, que lo obtiene cambiando el renderer y
     * sin tocar nada más.
     */
    public function testRunInvokesTheHandlerWithCoercedInputAndRendersResult(): void
    {
        $lines = [];
        $op = new Operation('echo', 'Echo', static fn (array $i): array => ['got' => $i], inputSchema: [
            'type' => 'object',
            'properties' => ['n' => ['type' => 'integer']],
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

        $code = $this->projector->run($op, ['--n=42'], $this->container, static function (string $l) use (&$lines): void {
            $lines[] = $l;
        });

        self::assertSame(0, $code);
        self::assertSame(['got:', '  n: 42'], $lines);
    }

    /**
     * Una entrada declarada `array` se arma repitiendo la bandera.
     *
     * El protocolo de tokens no podía transportar una lista: la bolsa cruda sólo producía cadenas y
     * la rama `array` del coercer exige un arreglo ya hecho, así que una entrada repetible era
     * imposible de satisfacer desde una terminal. Se descubrió al ir a convertir un comando con
     * filtros repetibles, no antes.
     */
    public function testRepeatedFlagsBecomeAListWhenTheSchemaSaysArray(): void
    {
        $op = new Operation('history', 'History', static fn (array $i): array => $i, inputSchema: [
            'type' => 'object',
            'properties' => [
                'producer' => ['type' => 'array'],
                'actor' => ['type' => 'string'],
            ],
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

        $input = $this->projector->deriveInput($op, ['--producer=a', '--producer=b', '--actor=x', '--actor=y']);

        self::assertSame(['producer' => ['a', 'b'], 'actor' => 'y'], $input, 'una lista acumula; un escalar gana el último');
    }

    /**
     * Una sola aparición TAMBIÉN llega como lista.
     *
     * La forma la decide el ESQUEMA y no cuántas veces se escribió la bandera. Si dependiera de eso,
     * el consumidor tendría que aceptar las dos formas, y ahí nace el `is_array()` defensivo que
     * este tipado existe para evitar.
     */
    public function testASingleOccurrenceIsStillAList(): void
    {
        $op = new Operation('history', 'History', static fn (array $i): array => $i, inputSchema: [
            'type' => 'object',
            'properties' => ['producer' => ['type' => 'array']],
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

        self::assertSame(['producer' => ['solo-uno']], $this->projector->deriveInput($op, ['--producer=solo-uno']));
    }

    public function testNullSchemaKeepsTheRawStringBag(): void
    {
        $op = new Operation('legacy', 'Legacy', static fn (array $i): array => $i,
            effects: new EffectProfile(
                mutation: Mutation::None,
                externality: Externality::None,
                reversibility: Reversibility::Guaranteed,
                authority: Authority::Read,
                subject: Subject::None,
                rollbackContract: 'test probe: nothing leaves this process',
            ),
        ); // inputSchema null

        $code = $this->projector->run($op, ['--a=1', '--b=x'], $this->container, static fn (string $l) => null);

        self::assertSame(0, $code);
    }

    public function testMutatingConfirmationIsRefusedWithoutASignedAuthorization(): void
    {
        // `--yes` is gone: it consented without naming what it consented to, so one yes covered
        // every plugin on every host. The gate now asks for a signature over this exact call, and
        // the refusal points at it. The four ways a signature can fail live in
        // {@see CliProjectorSignatureGateTest}; here only the door matters.
        $lines = [];
        $ran = false;
        $op = new Operation('wipe', 'Wipe', static function (array $i) use (&$ran): int {
            $ran = true;

            return 0;
        }, mutating: true, requiresConfirmation: true,
            effects: new EffectProfile(
                mutation: Mutation::Persistent,
                externality: Externality::None,
                reversibility: Reversibility::Guaranteed,
                authority: Authority::Read,
                subject: Subject::Data,
                rollbackContract: 'test probe: nothing leaves this process',
            ),
        );

        $code = $this->projector->run($op, ['--yes'], $this->container, static function (string $l) use (&$lines): void {
            $lines[] = $l;
        });

        self::assertSame(1, $code);
        self::assertFalse($ran, 'The old flag authorizes nothing.');
        self::assertStringContainsString('--sign', implode("\n", $lines));
    }

    public function testItNamesItsSurfaceAndClaimsOnlyOperationsThatOfferIt(): void
    {
        // The projector registry routes by these two answers. A projector that
        // claimed every operation would run HTTP-only ones from the terminal.
        $solaCli = new Operation('solo_cli', 'Solo CLI', static fn (array $i): array => $i, surfaces: ['cli'],
            effects: new EffectProfile(
                mutation: Mutation::None,
                externality: Externality::None,
                reversibility: Reversibility::Guaranteed,
                authority: Authority::Read,
                subject: Subject::None,
                rollbackContract: 'test probe: nothing leaves this process',
            ),
        );
        $solaHttp = new Operation('solo_http', 'Solo HTTP', static fn (array $i): array => $i, surfaces: ['http'],
            effects: new EffectProfile(
                mutation: Mutation::None,
                externality: Externality::None,
                reversibility: Reversibility::Guaranteed,
                authority: Authority::Read,
                subject: Subject::None,
                rollbackContract: 'test probe: nothing leaves this process',
            ),
        );

        // El projector, no el runner: `surface()` y `supports()` se quedaron en la mitad pura.
        $projector = new CliProjector();

        self::assertSame('cli', $projector->surface());
        self::assertTrue($projector->supports($solaCli));
        self::assertFalse($projector->supports($solaHttp));
    }

    public function testInputThatDoesNotMatchTheSchemaIsRefusedWithoutRunningTheHandler(): void
    {
        // Running the handler with a half-coerced bag is how a bad flag reaches
        // business logic as a 0.
        $ran = false;
        $lines = [];
        $op = new Operation('crear', 'Crear', static function (array $i) use (&$ran): array {
            $ran = true;

            return $i;
        }, inputSchema: [
            'type' => 'object',
            'properties' => ['n' => ['type' => 'integer']],
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

        $code = $this->projector->run($op, ['--n=muchos'], $this->container, static function (string $l) use (&$lines): void {
            $lines[] = $l;
        });

        self::assertSame(1, $code);
        self::assertFalse($ran, 'The handler never saw the bad input.');
        self::assertStringContainsString('✗', implode("\n", $lines));
    }

    /**
     * La mitad pura: `project()` deriva el comando del ESQUEMA, no de argv.
     *
     * Es la distinción que hace útil separarlo — argv pertenece a una invocación concreta, y esto
     * describe la superficie, que existe antes de que nadie escriba nada. Por eso se puede pedir para
     * imprimir una ayuda o generar autocompletado sin ejecutar la operación ni tener una terminal.
     */
    public function test_proyecta_el_comando_desde_el_esquema_sin_argv(): void
    {
        $op = new Operation(
            name: 'crear_post',
            description: 'Crea un post',
            handler: static fn (array $i): array => $i,
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'El título'],
                    'draft' => ['type' => 'boolean'],
                ],
                'required' => ['title'],
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

        self::assertSame('cli', $modelo->surface());
        self::assertSame('crear_post', $modelo->name);
        // La descripción viaja en el modelo; una propiedad que no la declara llega vacía, no ausente
        // — una llave que a veces está obliga a defenderse de ella en cada consumidor.
        self::assertSame([
            'title' => ['type' => 'string', 'required' => true, 'description' => 'El título'],
            'draft' => ['type' => 'boolean', 'required' => false, 'description' => ''],
        ], $modelo->flags);
        self::assertFalse($modelo->needsSignature);
    }

    /**
     * Que la operación exija firma viaja en el modelo, porque cambia lo que la superficie tiene que
     * pedir — y una ayuda honesta lo dice antes de que la persona escriba el comando.
     */
    public function test_el_modelo_anuncia_si_la_operacion_exige_firma(): void
    {
        $p = new CliProjector();
        $conFirma = new Operation('borrar', 'x', static fn (array $i) => $i, mutating: true, requiresConfirmation: true,
            effects: new EffectProfile(
                mutation: Mutation::Persistent,
                externality: Externality::None,
                reversibility: Reversibility::Guaranteed,
                authority: Authority::Read,
                subject: Subject::Data,
                rollbackContract: 'test probe: nothing leaves this process',
            ),
        );
        $sinFirma = new Operation('tocar', 'x', static fn (array $i) => $i, mutating: true,
            effects: new EffectProfile(
                mutation: Mutation::Persistent,
                externality: Externality::None,
                reversibility: Reversibility::Guaranteed,
                authority: Authority::Read,
                subject: Subject::Data,
                rollbackContract: 'test probe: nothing leaves this process',
            ),
        );

        self::assertTrue($p->project($conFirma)->needsSignature);
        self::assertFalse($p->project($sinFirma)->needsSignature);
    }

    /** Una operación sin esquema no tiene banderas que anunciar, y eso no es un error. */
    public function test_una_operacion_sin_esquema_proyecta_sin_banderas(): void
    {
        $modelo = (new CliProjector())->project(new Operation('ping', 'x', static fn () => null,
            effects: new EffectProfile(
                mutation: Mutation::None,
                externality: Externality::None,
                reversibility: Reversibility::Guaranteed,
                authority: Authority::Read,
                subject: Subject::None,
                rollbackContract: 'test probe: nothing leaves this process',
            ),
        ));

        self::assertSame([], $modelo->flags);
        self::assertSame(
            ['surface' => 'cli', 'name' => 'ping', 'description' => 'x', 'flags' => [], 'needsSignature' => false],
            $modelo->toArray(),
        );
    }
}

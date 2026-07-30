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

use Milpa\Console\Section\Section;
use Milpa\Console\Section\SectionProvider;
use Milpa\Console\State\InspectableSections;
use Milpa\Console\State\SectionStateProvider;
use Milpa\Console\State\SectionStateSource;
use Milpa\Console\Tui\ConsoleScreen;
use PHPUnit\Framework\TestCase;

/**
 * El shell: navegar entre las secciones que los plugins contribuyen, y pintarlas.
 *
 * Llegó a este paquete desde `milpa/admin` sin prueba propia — la suya cubría el COMANDO que lo
 * lanza, y ese comando se quedó allá. Un shell de 200 líneas que ahora es la cara del framework no
 * puede depender de que otro paquete lo pruebe de refilón.
 *
 * Nada de terminal real: `ConsoleScreen` toma ancho, alto y un flag de ANSI, así que se le puede
 * pedir un frame como cadena y leerlo.
 */
final class ConsoleScreenTest extends TestCase
{
    /** @param array<string, array<string, mixed>> $estados */
    private function pantalla(array $estados, ?string $inicial = null): ConsoleScreen
    {
        $plugin = new class ($estados) implements SectionProvider, SectionStateSource {
            /** @param array<string, array<string, mixed>> $estados */
            public function __construct(private readonly array $estados)
            {
            }

            /** @return list<Section> */
            public function sections(): array
            {
                $orden = 10;
                $out = [];
                foreach (array_keys($this->estados) as $id) {
                    $out[] = new Section($id, ucfirst($id), '/x/' . $id, $orden);
                    $orden += 10;
                }

                return $out;
            }

            /** @return array<string, SectionStateProvider> */
            public function sectionStates(): array
            {
                $out = [];
                foreach ($this->estados as $id => $estado) {
                    $out[$id] = new class ($estado) implements SectionStateProvider {
                        /** @param array<string, mixed> $estado */
                        public function __construct(private readonly array $estado)
                        {
                        }

                        /** @return array<string, mixed> */
                        public function state(): array
                        {
                            return $this->estado;
                        }
                    };
                }

                return $out;
            }

        };

        return new ConsoleScreen(new InspectableSections([$plugin]), 80, 24, false, $inicial);
    }

    public function test_abre_en_la_primera_seccion_cuando_no_se_pide_otra(): void
    {
        self::assertSame('alpha', $this->pantalla(['alpha' => ['a' => 1], 'beta' => ['b' => 2]])->currentSectionId());
    }

    public function test_abre_en_la_seccion_pedida(): void
    {
        self::assertSame('beta', $this->pantalla(['alpha' => [], 'beta' => []], 'beta')->currentSectionId());
    }

    /**
     * Una sección inicial que no existe no revienta ni deja la pantalla en blanco: cae a la primera.
     * Es un id equivocado en una línea de comando, no un estado imposible del sistema.
     */
    public function test_una_seccion_inicial_inexistente_cae_a_la_primera(): void
    {
        self::assertSame('alpha', $this->pantalla(['alpha' => [], 'beta' => []], 'no-existe')->currentSectionId());
    }

    public function test_las_flechas_y_hl_navegan_en_los_dos_sentidos(): void
    {
        $p = $this->pantalla(['alpha' => [], 'beta' => [], 'gamma' => []]);

        self::assertTrue($p->press('right'));
        self::assertSame('beta', $p->currentSectionId());
        self::assertTrue($p->press('l'));
        self::assertSame('gamma', $p->currentSectionId());
        self::assertTrue($p->press('left'));
        self::assertSame('beta', $p->currentSectionId());
        self::assertTrue($p->press('h'));
        self::assertSame('alpha', $p->currentSectionId());
    }

    public function test_un_digito_salta_a_la_seccion_por_posicion(): void
    {
        $p = $this->pantalla(['alpha' => [], 'beta' => [], 'gamma' => []]);

        self::assertTrue($p->press('3'));
        self::assertSame('gamma', $p->currentSectionId());
    }

    /**
     * Un dígito que no nombra ninguna sección se considera CONSUMIDO y no mueve nada. La alternativa
     * es que caiga al «Unhandled» del tier y le diga a la persona que su tecla no existe, cuando lo
     * que no existe es esa sección — está documentado así en el propio `handleKey()`.
     */
    public function test_un_digito_sin_seccion_se_consume_sin_mover_nada(): void
    {
        $p = $this->pantalla(['alpha' => [], 'beta' => []]);

        self::assertTrue($p->press('9'));
        self::assertSame('alpha', $p->currentSectionId());
    }

    /**
     * Una tecla que el shell no reconoce no mueve la sección — y aun así se reporta consumida,
     * porque `press()` delega en el loop del tier, que tiene su propio manejo por debajo. Lo que
     * importa aquí es lo segundo: la pantalla no cambia. Afirmar `false` sería afirmar sobre el
     * tier, no sobre este shell.
     */
    public function test_una_tecla_ajena_no_mueve_la_seccion(): void
    {
        $p = $this->pantalla(['alpha' => [], 'beta' => []]);

        $p->press('z');

        self::assertSame('alpha', $p->currentSectionId());
    }

    /**
     * El frame trae el estado de la sección ENFOCADA y nada más — el shell no dibuja una navegación
     * con los nombres de las demás; eso lo pone el tier alrededor. Sin ANSI para poder leerlo: lo
     * que se prueba es qué información llega, no cómo se pinta.
     */
    public function test_el_frame_trae_el_estado_de_la_seccion_enfocada(): void
    {
        $p = $this->pantalla([
            'alpha' => ['clave_de_alpha' => 'valor_de_alpha'],
            'beta' => ['clave_de_beta' => 'valor_de_beta'],
        ]);

        $frame = $p->render();

        self::assertStringContainsString('clave_de_alpha', $frame);
        self::assertStringContainsString('valor_de_alpha', $frame);
        self::assertStringNotContainsString('clave_de_beta', $frame, 'sólo se pinta el estado de la sección actual');

        $p->press('right');
        $frame = $p->render();
        self::assertStringContainsString('clave_de_beta', $frame);
        self::assertStringNotContainsString('clave_de_alpha', $frame, 'y al cambiar de sección, el anterior se va');
    }

    /**
     * El estado se relee en CADA frame. Un dashboard que congela lo que leyó al abrirse es una
     * captura de pantalla, y el código lo dice así en `tree()` — esta prueba lo sostiene.
     */
    public function test_el_estado_se_relee_en_cada_frame(): void
    {
        $vivo = new class () implements SectionStateProvider {
            public int $lecturas = 0;

            /** @return array<string, mixed> */
            public function state(): array
            {
                ++$this->lecturas;

                return ['lecturas' => $this->lecturas];
            }
        };

        $plugin = new class ($vivo) implements SectionProvider, SectionStateSource {
            public function __construct(private readonly SectionStateProvider $provider)
            {
            }

            /** @return list<Section> */
            public function sections(): array
            {
                return [new Section('vivo', 'Vivo', '/x/vivo', 10)];
            }

            /** @return array<string, SectionStateProvider> */
            public function sectionStates(): array
            {
                return ['vivo' => $this->provider];
            }
        };

        $p = new ConsoleScreen(new InspectableSections([$plugin]), 80, 24, false);

        self::assertStringContainsString('1', $p->render());
        self::assertStringContainsString('2', $p->render());
        self::assertSame(2, $vivo->lecturas);
    }

    /** Sin secciones no hay a qué enfocar, y eso no puede ser una excepción: un host vacío arranca igual. */
    public function test_sin_secciones_no_revienta(): void
    {
        $p = $this->pantalla([]);

        self::assertSame('', $p->currentSectionId());
        self::assertNotSame('', $p->render());
    }
}

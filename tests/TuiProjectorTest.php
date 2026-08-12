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

use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
use Milpa\Command\Effect\Subject;
use Milpa\Command\Operation;
use Milpa\Command\SurfaceModel;
use Milpa\Console\TuiProjector;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\ToolRuntime\Contracts\ToolContext;
use PHPUnit\Framework\TestCase;

/**
 * La cuarta superficie: una operación proyectada al árbol de nodos del TUI.
 *
 * Aquí vive el falsificador que ADR-0035 pre-registró y que hasta hoy fallaba en tres de cuatro
 * superficies: **cambiar el renderer sin tocar el projector**. En el TUI se puede porque el modelo es
 * un árbol de datos y el renderer lo consume; esta prueba lo ejerce con un renderer propio de veinte
 * líneas, escrito aquí mismo, que no comparte nada con el de `milpa/live-tui`.
 */
final class TuiProjectorTest extends TestCase
{
    private function operacion(): Operation
    {
        return new Operation(
            name: 'crear_post',
            description: 'Crea un post',
            handler: static fn (array $i): array => $i,
            inputSchema: [
                'type' => 'object',
                'properties' => ['title' => ['type' => 'string'], 'draft' => ['type' => 'boolean']],
                'required' => ['title'],
            ],
            mutating: true,
            requiresConfirmation: true,
            effects: new EffectProfile(
                mutation: Mutation::Persistent,
                externality: Externality::None,
                reversibility: Reversibility::Guaranteed,
                authority: Authority::Read,
                subject: Subject::Data,
                rollbackContract: 'test probe: nothing leaves this process',
            ),
        );
    }

    public function test_nombra_su_superficie_y_reclama_solo_lo_que_la_ofrece(): void
    {
        $p = new TuiProjector();

        self::assertSame('tui', $p->surface());
        self::assertTrue($p->supports(new Operation(
            'a',
            'x',
            static fn () => null,
            effects: new EffectProfile(
                mutation: Mutation::None,
                externality: Externality::None,
                reversibility: Reversibility::Guaranteed,
                authority: Authority::Read,
                subject: Subject::None,
                rollbackContract: 'test probe: nothing leaves this process',
            ),
        )));
        self::assertFalse($p->supports(new Operation(
            'b',
            'x',
            static fn () => null,
            surfaces: ['cli'],
            effects: new EffectProfile(
                mutation: Mutation::None,
                externality: Externality::None,
                reversibility: Reversibility::Guaranteed,
                authority: Authority::Read,
                subject: Subject::None,
                rollbackContract: 'test probe: nothing leaves this process',
            ),
        )));
    }

    public function test_proyecta_un_panel_con_un_campo_por_propiedad_del_esquema(): void
    {
        $modelo = (new TuiProjector())->project($this->operacion());

        self::assertInstanceOf(SurfaceModel::class, $modelo);
        self::assertSame('tui', $modelo->surface());
        self::assertSame('box', $modelo->node->type);
        self::assertSame('crear_post', $modelo->node->props['title']);

        $ids = array_map(static fn (TuiNode $n): string => $n->id, $modelo->node->children);
        self::assertSame(['descripcion', 'campo:title', 'campo:draft', 'firma'], $ids);
    }

    /**
     * Los campos son enfocables: es la diferencia entre un panel que se lee y uno que se puede
     * llenar. La descripción y el aviso de firma no lo son — no hay nada que escribir en ellos.
     */
    public function test_solo_los_campos_son_enfocables(): void
    {
        $modelo = (new TuiProjector())->project($this->operacion());

        $enfocables = array_values(array_map(
            static fn (TuiNode $n): string => $n->id,
            array_filter($modelo->node->children, static fn (TuiNode $n): bool => $n->focusable),
        ));

        self::assertSame(['campo:title', 'campo:draft'], $enfocables);
    }

    /**
     * Lo obligatorio sale del esquema, no del gusto de la pantalla. Es la misma regla que
     * `StateToNode` lleva escrita: decide estructura, nunca palabras.
     */
    public function test_lo_obligatorio_lo_dice_el_esquema(): void
    {
        $campos = [];
        foreach ((new TuiProjector())->project($this->operacion())->node->children as $n) {
            if (str_starts_with($n->id, 'campo:')) {
                $campos[$n->props['label']] = $n->props['required'];
            }
        }

        self::assertSame(['title' => true, 'draft' => false], $campos);
    }

    /** Una operación que no exige firma no anuncia una que no hay. */
    public function test_sin_firma_no_hay_aviso_de_firma(): void
    {
        $suave = new Operation(
            'listar',
            'Lista posts',
            static fn (array $i): array => $i,
            effects: new EffectProfile(
                mutation: Mutation::None,
                externality: Externality::None,
                reversibility: Reversibility::Guaranteed,
                authority: Authority::Read,
                subject: Subject::None,
                rollbackContract: 'test probe: nothing leaves this process',
            ),
        );

        $ids = array_map(
            static fn (TuiNode $n): string => $n->id,
            (new TuiProjector())->project($suave)->node->children,
        );

        self::assertNotContains('firma', $ids);
    }

    /**
     * EL FALSIFICADOR DE ADR-0035: cambiar el renderer sin tocar el projector.
     *
     * Este renderer de juguete no comparte una línea con `TuiNodeRendererRegistry` y produce texto
     * plano en vez de un buffer ANSI. Que consuma el mismo modelo sin que el projector se entere es
     * exactamente la cláusula 2, ejercida en vez de afirmada.
     */
    public function test_otro_renderer_consume_el_mismo_modelo(): void
    {
        $modelo = (new TuiProjector())->project($this->operacion());

        $aTexto = static function (TuiNode $n) use (&$aTexto): string {
            $propio = match ($n->type) {
                'box' => '# ' . $n->props['title'],
                'text' => $n->props['text'],
                'text-input' => '- ' . $n->props['label'] . ($n->props['required'] ? ' (obligatorio)' : ''),
                'badge' => '! ' . $n->props['text'],
                default => '',
            };

            return implode("\n", array_filter([$propio, ...array_map($aTexto, $n->children)]));
        };

        self::assertSame(
            "# crear_post\nCrea un post\n- title (obligatorio)\n- draft\n! requiere firma",
            $aTexto($modelo->node),
        );
    }

    /** Y el modelo serializa entero, hijos incluidos — la cláusula 4. */
    public function test_el_modelo_serializa_el_arbol_completo(): void
    {
        $plano = (new TuiProjector())->project($this->operacion())->toArray();

        self::assertSame('tui', $plano['surface']);
        self::assertCount(4, $plano['node']['children']);
        self::assertSame('campo:title', $plano['node']['children'][1]['id']);
        self::assertJson(json_encode($plano, JSON_THROW_ON_ERROR));
    }

    /** Proyectar es inerte: dos veces da lo mismo y no ocurre nada. */
    public function test_proyectar_dos_veces_es_inofensivo(): void
    {
        $p = new TuiProjector();
        $op = $this->operacion();

        self::assertEquals($p->project($op)->toArray(), $p->project($op)->toArray());
    }

    /** El canal `tui` existe y es propio: mismo principal que un shell, otra superficie. */
    public function test_el_canal_tui_es_propio_y_comparte_principal_con_el_shell(): void
    {
        $tui = ToolContext::tui();

        self::assertSame('tui', $tui->channel);
        self::assertSame('local-shell', $tui->principal);
        self::assertSame(ToolContext::cli()->principal, $tui->principal);
        self::assertNotSame(ToolContext::cli()->channel, $tui->channel);
    }
}

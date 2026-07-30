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

namespace Milpa\Console;

use Milpa\Command\Operation;
use Milpa\Command\SurfaceProjector;
use Milpa\Console\Model\TuiOperationModel;
use Milpa\Live\ValueObjects\Tui\TuiNode;

/**
 * Proyecta una operación al TUI: el árbol de nodos que la anuncia y pide sus argumentos.
 *
 * Era la superficie que faltaba. Las proyectadas hasta ahora eran CLI, HTTP y MCP, y sin ésta la
 * frase «una operación declarada una vez se proyecta a cada superficie que el host tenga montada»
 * era cierta para tres de cuatro — justo la que el framework pone al centro.
 *
 * ── LA SUPERFICIE QUE YA ESTABA BIEN ────────────────────────────────────────────────────────────
 *
 * Este projector no trae renderer, y no es un hueco: el TUI ya tenía el corte que ADR-0035 pide para
 * todos. `TuiNodeRendererRegistry` materializa un árbol de nodos a un buffer y `TuiAnsiPainter` lo
 * pinta, con más de treinta renderers intercambiables. Lo que faltaba era la mitad de ARRIBA — algo
 * que convirtiera una operación en ese árbol— y es lo único que agrega esta clase.
 *
 * Por eso el modelo no se inventa: es `TuiNode`, envuelto en {@see TuiOperationModel} sólo para
 * cumplir el contrato. Estructura, ninguna nueva.
 *
 * ── DECIDE ESTRUCTURA, NUNCA PALABRAS ───────────────────────────────────────────────────────────
 *
 * La misma regla que `StateToNode` de `milpa/live-tui` lleva escrita en su docblock, y que resultó
 * ser la invariante del ADR antes de que el ADR existiera. Aquí eso significa: los textos que salen
 * son los que la OPERACIÓN declaró —su nombre, su descripción, los nombres de sus campos— y este
 * projector no inventa ninguno. Un campo se anuncia obligatorio porque el esquema lo dice, no porque
 * a la pantalla le parezca.
 */
final class TuiProjector implements SurfaceProjector
{
    /** La superficie que este projector atiende — `tui`. */
    public function surface(): string
    {
        return 'tui';
    }

    /** Si la operación se ofrece a esta superficie; `surfaces: null` significa todas. */
    public function supports(Operation $op): bool
    {
        return $op->supportsSurface('tui');
    }

    /**
     * Construye el árbol: un panel con el nombre y la descripción, un campo por cada propiedad del
     * esquema, y una advertencia cuando la operación exige firma.
     *
     * Los campos son `focusable` porque el TUI navega por foco: es la diferencia entre un panel que
     * se lee y uno que se puede llenar. El aviso de firma va en el árbol y no en el renderer porque
     * es información de la operación, no de la pantalla — y una interfaz honesta lo dice antes de que
     * la persona empiece a escribir, no cuando ya iba a confirmar.
     */
    public function project(Operation $op): TuiOperationModel
    {
        /** @var array<string, array<string, mixed>> $propiedades */
        $propiedades = \is_array($op->inputSchema['properties'] ?? null) ? $op->inputSchema['properties'] : [];
        /** @var list<string> $obligatorias */
        $obligatorias = \is_array($op->inputSchema['required'] ?? null) ? array_values($op->inputSchema['required']) : [];

        $hijos = [new TuiNode('descripcion', 'text', props: ['text' => $op->description])];

        foreach ($propiedades as $nombre => $definicion) {
            $hijos[] = new TuiNode(
                'campo:' . $nombre,
                'text-input',
                props: [
                    'label' => $nombre,
                    'type' => \is_string($definicion['type'] ?? null) ? $definicion['type'] : 'string',
                    'required' => \in_array($nombre, $obligatorias, true),
                ],
                focusable: true,
            );
        }

        if ($op->mutating && $op->requiresConfirmation) {
            $hijos[] = new TuiNode('firma', 'badge', props: [
                'text' => 'requiere firma',
                'tone' => 'warning',
            ]);
        }

        return new TuiOperationModel(
            $op->name,
            new TuiNode('operacion:' . $op->name, 'box', props: ['title' => $op->name], children: $hijos),
        );
    }
}

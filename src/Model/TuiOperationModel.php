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

namespace Milpa\Console\Model;

use Milpa\Command\SurfaceModel;
use Milpa\Live\ValueObjects\Tui\TuiNode;

/**
 * Una operación vista desde el TUI: el árbol de nodos que la anuncia y pide sus argumentos.
 *
 * ── POR QUÉ ENVUELVE `TuiNode` EN VEZ DE REEMPLAZARLO ───────────────────────────────────────────
 *
 * ADR-0035 es explícito: cuando una superficie ya tiene modelo, se usa ése, y el del TUI es
 * `TuiNode` — un `TuiModel` paralelo con paneles y widgets propios sería la segunda fuente de verdad
 * de siempre. Así que aquí no se redefine ninguna estructura: esta clase LLEVA el árbol canónico y
 * sólo le agrega lo que el contrato `SurfaceModel` exige, que `TuiNode` no puede dar sin volverse
 * otra cosa. Un nodo no es una proyección; la raíz de un árbol sí.
 *
 * Que `toArray()` serialice el árbol completo es lo que hace cierta la promesa del ADR de poder
 * cambiar de renderer: el mismo modelo lo puede consumir el `TuiNodeRendererRegistry` de
 * `milpa/live-tui` o cualquier otra cosa que sepa leer nodos.
 */
final readonly class TuiOperationModel implements SurfaceModel
{
    public function __construct(
        public string $name,
        public TuiNode $node,
    ) {
    }

    /** La superficie de este modelo — `tui`. */
    public function surface(): string
    {
        return 'tui';
    }

    /**
     * El árbol completo como datos planos, recursivo por los hijos.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'surface' => 'tui',
            'name' => $this->name,
            'node' => self::nodo($this->node),
        ];
    }

    /** @return array<string, mixed> */
    private static function nodo(TuiNode $n): array
    {
        return [
            'id' => $n->id,
            'type' => $n->type,
            'props' => $n->props,
            'focusable' => $n->focusable,
            'children' => array_map(static fn (TuiNode $h): array => self::nodo($h), $n->children),
        ];
    }
}

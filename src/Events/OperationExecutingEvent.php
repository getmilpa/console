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

namespace Milpa\Console\Events;

use Milpa\Command\Operation;
use Milpa\Events\InterceptionSlot;

/**
 * Una operación está a punto de correr. Se puede detener, o contestar por ella.
 *
 * Llega con la `InterceptionSlot` del despacho: `stop()` la cancela —una política que niega, una
 * cuota agotada— y `shortCircuit($resultado)` la contesta sin que el handler corra, que es como se
 * enchufa un caché. Las dos cosas se distinguen a propósito: detener no es contestar, y quien llamó
 * necesita saber cuál de las dos pasó.
 *
 * ── POR QUÉ TRAE LA SUPERFICIE ──────────────────────────────────────────────────────────────────
 *
 * Porque la misma operación llega por cuatro caminos y no siempre merece la misma respuesta: una
 * política puede querer negar por HTTP lo que permite en la terminal de la máquina, y sin saber por
 * dónde entró tendría que adivinarlo.
 */
final readonly class OperationExecutingEvent
{
    /**
     * @param array<string, mixed> $input   ya coercionado a los tipos que el esquema declara
     * @param string               $surface `cli`, `http`, `tui` o `mcp`
     */
    public function __construct(
        public Operation $operation,
        public array $input,
        public string $surface,
    ) {
    }
}

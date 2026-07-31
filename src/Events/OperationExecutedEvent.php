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

/**
 * Una operación tuvo un desenlace — cualquiera de ellos.
 *
 * Se emite SIEMPRE: cuando el handler contestó, cuando alguien contestó por él, cuando alguien la
 * detuvo, y cuando lanzó. Una auditoría con huecos es peor que ninguna, porque enseña a confiar en
 * una lista incompleta: lo que no aparece se lee como que no pasó.
 *
 * Los tres casos se distinguen en el evento en vez de colapsarse en «corrió/no corrió». Quien
 * audita necesita saber si un resultado salió de un caché, y quien depura necesita saber si algo no
 * corrió porque nadie lo dejó o porque tronó.
 */
final readonly class OperationExecutedEvent
{
    /**
     * @param array<string, mixed> $input
     * @param string               $surface        `cli`, `http`, `tui` o `mcp`
     * @param mixed                $result         lo que contestó; `null` si se detuvo o falló
     * @param bool                 $shortCircuited un listener contestó por el handler
     * @param bool                 $stopped        un listener la canceló; nadie contestó
     * @param \Throwable|null      $error          lo que lanzó, si lanzó
     */
    public function __construct(
        public Operation $operation,
        public array $input,
        public string $surface,
        public mixed $result,
        public bool $shortCircuited = false,
        public bool $stopped = false,
        public ?\Throwable $error = null,
    ) {
    }

    /** Si la operación llegó a correr de verdad — ni servida, ni detenida, ni fallida. */
    public function ran(): bool
    {
        return !$this->shortCircuited && !$this->stopped && $this->error === null;
    }
}

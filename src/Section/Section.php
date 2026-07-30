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

namespace Milpa\Console\Section;

/**
 * Una sección del shell — la unidad que un plugin contribuye al Hub (ADR#12). El contrato es
 * deliberadamente mínimo: solo lo que los consumidores ACTUALES leen (el sidebar renderiza
 * id/title/href; el discovery ordena por order). Campos futuros (mode/icon/scopes) entran cuando
 * algo los muerda, no antes.
 */
final readonly class Section
{
    public function __construct(
        public string $id,      // gramática: ^[a-z][a-z0-9.-]*$
        public string $title,
        public string $href,    // path LOCAL ABSOLUTO (/milpa/admin/settings, /agency/architecture)
        public int $order = 0,
    ) {
    }
}

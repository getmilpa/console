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

namespace Milpa\Console\State;

/**
 * El extension point opt-in que un plugin implementa para declarar el ESTADO de sus secciones — el
 * paralelo de {@see \Milpa\Console\Section\SectionProvider} (que declara las
 * secciones) para el estado. {@see SectionStateDiscovery} lo descubre por `instanceof`.
 */
interface SectionStateSource
{
    /**
     * El estado read-only de las secciones que este plugin aporta, keyed por id de sección.
     *
     * @return array<string, SectionStateProvider>
     */
    public function sectionStates(): array;
}

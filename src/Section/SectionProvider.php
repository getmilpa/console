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
 * El extension point `ui.admin.section` (nombre conceptual — la verdad ejecutable es ESTA interfaz,
 * no una capability del resolver). Un plugin booteado que la implemente contribuye secciones al
 * Admin Hub, que las descubre vía instanceof — el mismo idioma que CommandProvider::operations()
 * y getToolProviderPromptSections().
 */
interface SectionProvider
{
    /**
     * Las secciones que este plugin aporta al panel.
     *
     * @return list<Section>
     */
    public function sections(): array;
}

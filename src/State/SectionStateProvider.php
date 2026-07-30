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
 * El estado read-only de una sección del shell — el contrato que CUALQUIER superficie (HTML, CLI,
 * TUI, API…) consume para preguntar "¿cuál es el estado de esta sección?". El estado pertenece a la
 * SECCIÓN (dominio), no a la UI: el shell lo renderiza, nunca lo produce. Lo implementan
 * {@see \Milpa\Admin\Settings\SettingsStateProvider} (config) y
 * {@see RoutesStateProvider} (rutas). El array es section-específico: cada renderer sabe interpretar el suyo.
 */
interface SectionStateProvider
{
    /**
     * El estado read-only de la sección, como array que su renderer sabe interpretar.
     *
     * @return array<string,mixed>
     */
    public function state(): array;
}

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
 * Resuelve un id de sección → su {@see SectionStateProvider}, juntando lo que declaran los plugins
 * {@see SectionStateSource} booteados — mismo idioma que
 * {@see \Milpa\Console\Section\SectionDiscovery}. Es el link sección→estado que
 * deja a cualquier shell (web, CLI, …) obtener el estado de una sección sin conocer su controller.
 */
final class SectionStateDiscovery
{
    /** @var ?array<string, SectionStateProvider> */
    private ?array $snapshot = null;

    /** @param iterable<object> $plugins los plugins booteados (PluginsManagerInterface::getPlugins()) */
    public function __construct(private readonly iterable $plugins)
    {
    }

    /** El provider de estado de la sección `$sectionId`, o `null` si ninguna fuente lo declara. */
    public function providerFor(string $sectionId): ?SectionStateProvider
    {
        return $this->all()[$sectionId] ?? null;
    }

    /**
     * El mapa completo id de sección → su provider de estado, de todas las fuentes.
     *
     * @return array<string, SectionStateProvider>
     */
    public function all(): array
    {
        if ($this->snapshot !== null) {
            return $this->snapshot;
        }

        $map = [];
        foreach ($this->plugins as $plugin) {
            if (!$plugin instanceof SectionStateSource) {
                continue;
            }
            foreach ($plugin->sectionStates() as $sectionId => $provider) {
                $map[$sectionId] = $provider;
            }
        }

        return $this->snapshot = $map;
    }
}

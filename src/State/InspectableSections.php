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

use Milpa\Console\Section\SectionDiscovery;
use Milpa\Console\Section\SectionDiscoveryException;

/**
 * Las secciones del shell que exponen estado inspectable, en orden y con
 * el título que ya declararon — el motor único del que salen las DOS audiencias
 * de `coa:tui`: el JSON headless que lee un agente y el dashboard que navega
 * una persona.
 *
 * Existe porque las dos mitades necesitan exactamente lo mismo —qué secciones
 * hay, en qué orden, cómo se llaman y cuál es su estado— y armarlo dos veces
 * es cómo el JSON y el TUI terminan contestando cosas distintas sobre la misma
 * app. La sección es la unidad de navegación de una y de recorrido de la otra.
 */
final class InspectableSections
{
    /** @var ?list<array{id: string, title: string, href: string, provider: SectionStateProvider}> */
    private ?array $snapshot = null;

    /** @param iterable<object> $plugins los plugins booteados (PluginsManagerInterface::getPlugins()) */
    public function __construct(private readonly iterable $plugins)
    {
    }

    /**
     * Las secciones con estado, en el orden en que las declaran sus fuentes.
     *
     * Una sección sin estado inspectable —`architecture`, que es web-only— no
     * aparece: no hay nada que mostrar ni sobre qué navegar.
     *
     * @return list<array{id: string, title: string, href: string, provider: SectionStateProvider}>
     */
    public function all(): array
    {
        if ($this->snapshot !== null) {
            return $this->snapshot;
        }

        $titles = [];
        $hrefs = [];
        // El menú es una preocupación WEB, y la inspectabilidad no. Un host que expone estado sin
        // publicar páginas —el shell de un runtime en la terminal, por ejemplo— no tiene `href` que
        // declarar, y exigírselo lo obligaría a inventar rutas que no existen.
        //
        // El fallback de abajo ya contemplaba ese caso desde el primer día, pero nunca se alcanzaba:
        // el descubrimiento del menú lanzaba antes. El comentario decía que estaba soportado y el
        // código decía que no; ganaba el código.
        try {
            foreach ((new SectionDiscovery($this->plugins))->sections() as $section) {
                $titles[$section->id] = $section->title;
                $hrefs[$section->id] = $section->href;
            }
        } catch (SectionDiscoveryException) {
            // Sin menú se sigue: lo que decide qué es inspectable es el estado, no la navegación.
        }

        $sections = [];
        foreach ((new SectionStateDiscovery($this->plugins))->all() as $id => $provider) {
            $sections[] = [
                'id' => $id,
                // Una sección que expone estado sin declararse en el menú sigue
                // siendo inspectable; su id es el mejor nombre que tenemos.
                'title' => $titles[$id] ?? $id,
                'href' => $hrefs[$id] ?? '',
                'provider' => $provider,
            ];
        }

        return $this->snapshot = $sections;
    }

    /**
     * Solo los ids, que es lo que el loop necesita como orden de navegación.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        return array_map(static fn (array $section): string => $section['id'], $this->all());
    }

    /**
     * La sección `$id`, o null si no expone estado.
     *
     * @return array{id: string, title: string, href: string, provider: SectionStateProvider}|null
     */
    public function find(string $id): ?array
    {
        foreach ($this->all() as $section) {
            if ($section['id'] === $id) {
                return $section;
            }
        }

        return null;
    }
}

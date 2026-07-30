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

namespace Milpa\Console\Tests\State;

use Milpa\Console\State\SectionStateDiscovery;
use Milpa\Console\State\SectionStateProvider;
use Milpa\Console\State\SectionStateSource;
use PHPUnit\Framework\TestCase;

/**
 * `SectionStateDiscovery` resuelve un id de sección → su `SectionStateProvider`, juntando el
 * estado que declaran los plugins `SectionStateSource` booteados — mismo idioma de discovery que
 * `SectionDiscovery` (instanceof sobre los plugins). Es el link sección→estado que permite que un
 * shell (CLI) obtenga el estado de una sección sin routear a un controller web.
 */
final class SectionStateDiscoveryTest extends TestCase
{
    private function provider(array $state): SectionStateProvider
    {
        return new class ($state) implements SectionStateProvider {
            public function __construct(private readonly array $state)
            {
            }

            public function state(): array
            {
                return $this->state;
            }
        };
    }

    private function source(array $map): SectionStateSource
    {
        return new class ($map) implements SectionStateSource {
            /** @param array<string, SectionStateProvider> $map */
            public function __construct(private readonly array $map)
            {
            }

            public function sectionStates(): array
            {
                return $this->map;
            }
        };
    }

    public function test_provider_for_resolves_a_declared_section(): void
    {
        $settings = $this->provider(['siteName' => 'Acme']);
        $discovery = new SectionStateDiscovery([
            $this->source(['settings' => $settings]),
            new \stdClass(), // un plugin que NO es SectionStateSource se ignora
        ]);

        self::assertSame($settings, $discovery->providerFor('settings'));
        self::assertSame(['siteName' => 'Acme'], $discovery->providerFor('settings')->state());
    }

    public function test_provider_for_unknown_section_is_null(): void
    {
        $discovery = new SectionStateDiscovery([$this->source(['settings' => $this->provider([])])]);

        self::assertNull($discovery->providerFor('system'));
    }

    public function test_all_returns_the_full_map_across_sources(): void
    {
        $discovery = new SectionStateDiscovery([
            $this->source(['settings' => $this->provider([])]),
            $this->source(['system' => $this->provider(['routes' => []])]),
        ]);

        self::assertSame(['settings', 'system'], array_keys($discovery->all()));
    }
}

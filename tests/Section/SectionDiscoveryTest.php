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

namespace Milpa\Console\Tests\Section;

use Milpa\Console\Section\Section;
use Milpa\Console\Section\SectionDiscovery;
use Milpa\Console\Section\SectionDiscoveryException;
use Milpa\Console\Section\SectionProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Provider sintético — cualquier objeto sirve; el discovery filtra por instanceof. */
final class FakeSectionProvider implements SectionProvider
{
    /** @param list<Section> $sections */
    public function __construct(private readonly array $sections)
    {
    }

    public function sections(): array
    {
        return $this->sections;
    }
}

final class NotAProvider
{
}

final class SectionDiscoveryTest extends TestCase
{
    public function test_merges_sections_from_multiple_providers_and_ignores_non_providers(): void
    {
        $discovery = new SectionDiscovery([
            new FakeSectionProvider([new Section('settings', 'Settings', '/milpa/admin/settings', 10)]),
            new NotAProvider(),
            new FakeSectionProvider([new Section('architecture', 'Arquitectura', '/agency/architecture', 20)]),
        ]);

        $ids = array_map(static fn (Section $s): string => $s->id, $discovery->sections());
        self::assertSame(['settings', 'architecture'], $ids);
    }

    public function test_order_is_deterministic_by_order_then_id_never_boot_order(): void
    {
        // mismo order (5) → desempata por id alfabético, NO por orden de registro
        $discovery = new SectionDiscovery([
            new FakeSectionProvider([new Section('zeta', 'Zeta', '/z', 5)]),
            new FakeSectionProvider([new Section('alfa', 'Alfa', '/a', 5)]),
            new FakeSectionProvider([new Section('primero', 'Primero', '/p', 1)]),
        ]);

        $ids = array_map(static fn (Section $s): string => $s->id, $discovery->sections());
        self::assertSame(['primero', 'alfa', 'zeta'], $ids);
    }

    public function test_default_section_is_the_first_by_order(): void
    {
        $discovery = new SectionDiscovery([
            new FakeSectionProvider([
                new Section('b', 'B', '/b', 20),
                new Section('a', 'A', '/a', 10),
            ]),
        ]);

        self::assertSame('a', $discovery->defaultSection()->id);
    }

    public function test_duplicate_ids_block_with_the_duplicate_code(): void
    {
        $discovery = new SectionDiscovery([
            new FakeSectionProvider([new Section('settings', 'Settings', '/x', 1)]),
            new FakeSectionProvider([new Section('settings', 'Otra', '/y', 2)]),
        ]);

        try {
            $discovery->sections();
            self::fail('debió lanzar por id duplicado');
        } catch (SectionDiscoveryException $e) {
            self::assertStringContainsString('MILPA_ADMIN_SECTION_DUPLICATE', $e->getMessage());
            self::assertStringContainsString('settings', $e->getMessage());
        }
    }

    #[DataProvider('invalidSections')]
    public function test_invalid_sections_block_with_the_invalid_code(Section $bad): void
    {
        $discovery = new SectionDiscovery([new FakeSectionProvider([$bad])]);

        try {
            $discovery->sections();
            self::fail('debió lanzar por sección inválida');
        } catch (SectionDiscoveryException $e) {
            self::assertStringContainsString('MILPA_ADMIN_SECTION_INVALID', $e->getMessage());
        }
    }

    /** @return iterable<string, array{Section}> */
    public static function invalidSections(): iterable
    {
        yield 'id vacío' => [new Section('', 'T', '/x')];
        yield 'id con mayúsculas' => [new Section('Settings', 'T', '/x')];
        yield 'id que arranca con dígito' => [new Section('1abc', 'T', '/x')];
        yield 'title vacío' => [new Section('ok', '', '/x')];
        yield 'href vacío' => [new Section('ok', 'T', '')];
        yield 'href relativo' => [new Section('ok', 'T', 'agency/architecture')];
        yield 'href protocol-relative' => [new Section('ok', 'T', '//evil.example')];
        yield 'href con esquema' => [new Section('ok', 'T', 'https://evil.example')];
        yield 'href con control char' => [new Section('ok', 'T', "/x\t/y")];
    }

    public function test_zero_sections_block_with_the_empty_code(): void
    {
        $discovery = new SectionDiscovery([new NotAProvider()]);

        try {
            $discovery->sections();
            self::fail('debió lanzar por cero secciones');
        } catch (SectionDiscoveryException $e) {
            self::assertStringContainsString('MILPA_ADMIN_NO_SECTIONS', $e->getMessage());
        }
    }
}

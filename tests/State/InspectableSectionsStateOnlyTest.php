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

use Milpa\Console\State\SectionStateProvider;
use Milpa\Console\State\SectionStateSource;
use Milpa\Console\State\InspectableSections;
use PHPUnit\Framework\TestCase;

/**
 * Una fuente que expone ESTADO sin declarar menú sigue siendo inspectable.
 *
 * El código lo decía en un comentario desde el primer día —«su id es el mejor nombre que tenemos»—
 * y nunca llegaba ahí: el descubrimiento del menú lanzaba antes por no encontrar secciones. El
 * comentario decía que estaba soportado y el código decía que no, y ganaba el código.
 *
 * Lo destapó el primer consumidor que tenía estado sin páginas web: el shell del runtime de `coa`,
 * cuyas superficies —framework, rutas, plugins, herramientas— se miran en la terminal y no tienen
 * URL que declarar. Exigirle un `href` lo habría obligado a inventar rutas inexistentes.
 */
final class InspectableSectionsStateOnlyTest extends TestCase
{
    public function test_a_source_with_state_and_no_menu_is_still_inspectable(): void
    {
        $fuente = new class () implements SectionStateSource {
            /** @return array<string, SectionStateProvider> */
            public function sectionStates(): array
            {
                return ['runtime' => new class () implements SectionStateProvider {
                    /** @return array<string,mixed> */
                    public function state(): array
                    {
                        return ['rutas' => 204];
                    }
                }];
            }
        };

        $secciones = (new InspectableSections([$fuente]))->all();

        self::assertCount(1, $secciones);
        self::assertSame('runtime', $secciones[0]['id']);
        self::assertSame('runtime', $secciones[0]['title'], 'sin menú, el id es el nombre');
        self::assertSame('', $secciones[0]['href'], 'sin página web no hay href que inventar');
        self::assertSame(['rutas' => 204], $secciones[0]['provider']->state());
    }
}

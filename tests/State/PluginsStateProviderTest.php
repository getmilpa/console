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

use Milpa\Container\DIContainer;
use Milpa\Interfaces\Plugin\PluginInstallerInterface;
use Milpa\Plugin\Contracts\PluginRecord;
use Milpa\Plugin\Contracts\PluginRegistryInterface;
use Milpa\Plugin\Registry\InMemoryPluginRegistry;
use Milpa\Console\State\PluginsStateProvider;
use PHPUnit\Framework\TestCase;

/**
 * El estado de la sección "Plugins" del panel.
 *
 * Lo que importa acá no es que devuelva filas: es de DÓNDE las saca. El proveedor maneja las
 * operaciones de `milpa/plugin`, no el registro — si algún día alguien lo "simplifica" leyendo el
 * registro directo, el panel y la terminal empiezan a ser dos implementaciones de la misma
 * pregunta, y la primera vez que no coincidan nadie va a saber cuál miente.
 */
final class PluginsStateProviderTest extends TestCase
{
    private function registry(): InMemoryPluginRegistry
    {
        return new InMemoryPluginRegistry();
    }

    private function record(string $name, bool $enabled = true): PluginRecord
    {
        return new PluginRecord(
            name: $name,
            version: '1.0.0',
            author: 'TeamX',
            site: 'https://teamx.agency',
            type: 'Service',
            installed: true,
            enabled: $enabled,
        );
    }

    private function container(?PluginRegistryInterface $registry, ?PluginInstallerInterface $installer = null): DIContainer
    {
        $container = new DIContainer();
        if ($registry !== null) {
            $container->registerService(PluginRegistryInterface::class, $registry);
        }
        if ($installer !== null) {
            $container->registerService(PluginInstallerInterface::class, $installer);
        }

        return $container;
    }

    public function test_lista_los_plugins_que_el_host_tiene_registrados(): void
    {
        $registry = $this->registry();
        $registry->register($this->record('MilpaAdminPlugin'));
        $registry->register($this->record('OAuthPlugin', enabled: false));

        $state = PluginsStateProvider::fromContainer($this->container($registry))->state();

        self::assertTrue($state['available']);
        self::assertSame(
            ['MilpaAdminPlugin', 'OAuthPlugin'],
            array_column($state['plugins'], 'name'),
        );
        self::assertSame([true, false], array_column($state['plugins'], 'enabled'));
    }

    public function test_sin_instalador_cableado_la_seccion_no_ofrece_instalar(): void
    {
        // El panel pinta lo que el host puede hacer. Sin instalador, un botón de instalar sería un
        // formulario cuyo POST no tiene a quién llamar.
        $state = PluginsStateProvider::fromContainer($this->container($this->registry()))->state();

        self::assertFalse($state['canInstall']);
    }

    public function test_con_instalador_cableado_la_seccion_si_ofrece_instalar(): void
    {
        $state = PluginsStateProvider::fromContainer(
            $this->container($this->registry(), $this->installer()),
        )->state();

        self::assertTrue($state['canInstall']);
    }

    public function test_un_host_sin_registro_se_reporta_no_disponible_en_vez_de_tronar(): void
    {
        // Un host puede no tener almacén de activación. La página tiene que decirlo, no reventar:
        // es la sección de administrar plugins, y la usa alguien que está diagnosticando.
        $state = PluginsStateProvider::fromContainer($this->container(null))->state();

        self::assertFalse($state['available']);
        self::assertSame([], $state['plugins']);
        self::assertFalse($state['canInstall']);
    }

    public function test_conmutar_mueve_el_estado_y_devuelve_null_cuando_funciono(): void
    {
        $registry = $this->registry();
        $registry->register($this->record('OAuthPlugin', enabled: true));
        $provider = PluginsStateProvider::fromContainer($this->container($registry));

        self::assertNull($provider->toggle('OAuthPlugin', false));
        self::assertFalse($registry->find('OAuthPlugin')?->enabled);

        self::assertNull($provider->toggle('OAuthPlugin', true));
        self::assertTrue($registry->find('OAuthPlugin')?->enabled);
    }

    public function test_conmutar_algo_que_no_existe_devuelve_el_motivo_y_no_lanza(): void
    {
        // Quien llama es el POST de un panel: un stack trace en la cara no le dice a nadie qué
        // hacer, y el motivo sí.
        $provider = PluginsStateProvider::fromContainer($this->container($this->registry()));

        $failure = $provider->toggle('Fantasma', true);

        self::assertNotNull($failure);
        self::assertStringContainsString('Fantasma', $failure);
    }

    public function test_un_host_sin_registro_no_puede_conmutar_y_lo_dice(): void
    {
        $provider = PluginsStateProvider::fromContainer($this->container(null));

        self::assertSame('Este host no puede conmutar plugins.', $provider->toggle('Lo que sea', true));
    }

    /** Un instalador inerte: sólo existe para que las operaciones de instalar aparezcan. */
    private function installer(): PluginInstallerInterface
    {
        return new class () implements PluginInstallerInterface {
            public function require(string $source): \Milpa\DTO\PluginInstallResult
            {
                throw new \LogicException('no se instala nada en este test');
            }

            public function update(string $pluginName, ?string $targetVersion = null): \Milpa\DTO\PluginInstallResult
            {
                throw new \LogicException('no se actualiza nada en este test');
            }

            public function resolve(string $source): \Milpa\DTO\DependencyResolution
            {
                return new \Milpa\DTO\DependencyResolution(resolvable: true);
            }

            public function remove(string $pluginName, bool $keepData = false): \Milpa\DTO\PluginRemoveResult
            {
                throw new \LogicException('no se quita nada en este test');
            }
        };
    }
}

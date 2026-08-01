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

use Milpa\Command\Operation;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInstallerInterface;
use Milpa\Plugin\Contracts\PluginRegistryInterface;
use Milpa\Plugin\Activation\DeclaredPlugins;
use Milpa\Plugin\Operations\PluginOperations;
use Milpa\Plugin\Runtime\MetadataActivationSafety;

/**
 * El estado de la sección "Plugins": qué plugins tiene este host y cuáles arrancan.
 *
 * Maneja las OPERACIONES de `milpa/plugin`, no el registro. Leer el registro directo desde acá
 * sería una segunda implementación de "qué plugins hay" — y la primera vez que las dos no
 * coincidan, el panel y la terminal van a estar diciendo cosas distintas sobre el mismo host. La
 * operación es la única definición; esta clase sólo la invoca.
 *
 * Qué operaciones existen lo decide el host por lo que cableó: sin un
 * {@see PluginInstallerInterface} en el container, `plugins.install` sencillamente no existe y la
 * vista no pinta el botón. Eso no se decide acá.
 */
final readonly class PluginsStateProvider implements SectionStateProvider
{
    /** @param list<Operation> $operations */
    public function __construct(private array $operations)
    {
    }

    /**
     * Arma el proveedor desde el container del host.
     *
     * Sin registro no hay nada que administrar, así que se arma vacío: la sección se pinta
     * diciendo que este host no tiene almacén de activación, en vez de tronar la página entera.
     */
    public static function fromContainer(DIContainerInterface $container): self
    {
        $registry = $container->tryGet(PluginRegistryInterface::class);
        if (!$registry instanceof PluginRegistryInterface) {
            return new self([]);
        }

        $installer = $container->tryGet(PluginInstallerInterface::class);

        // El evaluador de seguridad al apagar, igual que en la terminal. Sin esta línea la TUI era
        // otra puerta al mismo defecto: `plugins.disable` sin nadie que compruebe si el grafo
        // seguiría cerrando. Que una superficie ofrezca lo que la otra niega es precisamente lo que
        // este repositorio lleva semanas quitando — cuatro comparadores de capacidad, dos sistemas de
        // aprobación, y ahora dos puertas al mismo apagado.
        $declared = $container->tryGet(DeclaredPlugins::class);
        $safety = $declared instanceof DeclaredPlugins && $declared->classes !== []
            ? new MetadataActivationSafety($declared->classes, $registry)
            : null;

        return new self((new PluginOperations(
            $registry,
            $installer instanceof PluginInstallerInterface ? $installer : null,
            $declared instanceof DeclaredPlugins ? $declared->classes : [],
            $safety,
        ))->operations());
    }

    /**
     * La lista de plugins más qué puede hacer esta superficie con ellos.
     *
     * `canInstall` no es cosmético: viaja para que la vista pinte lo que el host realmente puede
     * hacer, y no un formulario cuyo POST no tiene a quién llamar.
     *
     * @return array{plugins: list<array<string, mixed>>, canInstall: bool, available: bool}
     */
    public function state(): array
    {
        $list = $this->operation('plugins.list');
        if ($list === null) {
            return ['plugins' => [], 'canInstall' => false, 'available' => false];
        }

        /** @var array{plugins: list<array<string, mixed>>} $result */
        $result = ($list->handler)([]);

        return [
            'plugins' => $result['plugins'],
            'canInstall' => $this->operation('plugins.install') !== null,
            'available' => true,
        ];
    }

    /**
     * Prende o apaga un plugin por nombre. Devuelve **null si funcionó**, o el motivo si no.
     *
     * El resultado se lee por el tipo, no por la forma del texto: quien llama pregunta
     * `=== null`, y ningún cambio de redacción puede volver un fallo en un éxito.
     *
     * Traduce la excepción de la operación a texto y no la deja subir: quien llama es el POST de
     * un panel, y un stack trace en la cara no le dice a nadie qué hacer.
     */
    public function toggle(string $name, bool $enabled): ?string
    {
        $operation = $this->operation($enabled ? 'plugins.enable' : 'plugins.disable');
        if ($operation === null) {
            return 'Este host no puede conmutar plugins.';
        }

        try {
            ($operation->handler)(['name' => $name]);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** La operación con ese nombre, o null si este host no la ofrece. */
    private function operation(string $name): ?Operation
    {
        foreach ($this->operations as $operation) {
            if ($operation->name === $name) {
                return $operation;
            }
        }

        return null;
    }
}

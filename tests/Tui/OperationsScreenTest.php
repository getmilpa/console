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

namespace Milpa\Console\Tests\Tui;

use Milpa\Command\Operation;
use Milpa\Console\Tui\OperationsScreen;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * El shell: todo lo que la app sabe hacer, y cualquiera de esas cosas corrible.
 *
 * La lista se DERIVA de los átomos, igual que la ayuda de `coa`. Una pantalla escrita a mano sería el
 * primer archivo que miente cuando alguien instala un plugin.
 */
final class OperationsScreenTest extends TestCase
{
    private function container(): ContainerInterface
    {
        return new class () implements ContainerInterface {
            public function get(string $id): mixed
            {
                return null;
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
    }

    /** @return list<Operation> */
    private function operaciones(): array
    {
        return [
            new Operation('plugins_enable', 'Enciende un plugin', static fn (array $i): array => ['ok' => true, 'name' => $i['name'] ?? '?'], inputSchema: ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']], mutating: true),
            new Operation('plugins_list', 'Lista los plugins', static fn (array $i): array => ['ok' => true, 'total' => 3], inputSchema: ['type' => 'object', 'properties' => []]),
            new Operation('solo_cli', 'Sólo terminal', static fn (array $i): array => ['ok' => true], inputSchema: ['type' => 'object', 'properties' => []], surfaces: ['cli']),
        ];
    }

    private function shell(): OperationsScreen
    {
        return new OperationsScreen($this->operaciones(), $this->container(), 74, 20, false);
    }

    /**
     * Consultan primero, cambian algo después — y lo que no se ofrece a esta superficie no aparece.
     *
     * El orden es el de una decisión: se mira antes de tocar. Y una operación declarada `cli` que
     * apareciera aquí sería una fuga: su autor dijo dónde se ofrece.
     */
    public function testItListsWhatTheSurfaceOffersReadFirst(): void
    {
        $nombres = array_map(static fn (Operation $op): string => $op->name, $this->shell()->operations());

        self::assertSame(['plugins_list', 'plugins_enable'], $nombres);
    }

    /** Enter abre la operación enfocada, y lo que se ve pasa a ser la operación. */
    public function testEnterOpensTheFocusedOperation(): void
    {
        $shell = $this->shell();
        self::assertNull($shell->openOperation());

        $shell->press('enter');

        self::assertSame('plugins_list', $shell->openOperation());
        self::assertStringContainsString('Lista los plugins', $shell->render());
    }

    /**
     * Escape cierra y devuelve a la lista.
     *
     * Sin una salida clara, un TUI que entra en algo es una trampa: la alternativa es ctrl+c, que
     * mata el proceso en vez de cerrar la pantalla.
     */
    public function testEscapeGoesBackToTheList(): void
    {
        $shell = $this->shell();
        $shell->press('enter');
        self::assertNotNull($shell->openOperation());

        $shell->press('escape');

        self::assertNull($shell->openOperation());
        self::assertStringContainsString('plugins_enable', $shell->render(), 'volvimos a la lista');
    }

    /** Con una operación abierta, las teclas van a ELLA y no a la lista. */
    public function testKeysGoToTheOpenOperation(): void
    {
        $shell = $this->shell();
        $shell->press('tab');      // enfoca plugins_enable
        $shell->press('enter');    // la abre
        self::assertSame('plugins_enable', $shell->openOperation());

        foreach (['M', 'i', 'P', 'l', 'u', 'g', 'i', 'n'] as $tecla) {
            $shell->press($tecla);
        }
        $shell->press('enter');

        self::assertStringContainsString('MiPlugin', $shell->render());
    }

    /** Lo que exige firma se MARCA en la lista, antes de entrar. */
    public function testWhatDemandsASignatureIsMarkedInTheList(): void
    {
        $conFirma = new Operation('plugins_remove', 'Quita un plugin', static fn (array $i): array => ['ok' => true], inputSchema: ['type' => 'object', 'properties' => []], mutating: true, requiresConfirmation: true);
        $shell = new OperationsScreen([$conFirma], $this->container(), 74, 12, false);

        self::assertStringContainsString('⚠', $shell->render());
    }

    /** Una app sin operaciones para esta superficie lo dice, en vez de pintar una lista vacía. */
    public function testAnAppWithNothingToOfferSaysSo(): void
    {
        $shell = new OperationsScreen([], $this->container(), 60, 10, false);

        self::assertStringContainsString('no declara ninguna operación', $shell->render());
    }
}

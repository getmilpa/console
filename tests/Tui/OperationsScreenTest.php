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

use Milpa\Console\Tui\ScreenLabels;
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

        self::assertStringContainsString('declares no operation', $shell->render());
    }


    /**
     * 🚨 EVERY WORD THIS SCREEN SHOWS COMES FROM THE HOST, AND THE DEFAULTS ARE ENGLISH.
     *
     * `{@see ScreenLabels}` was built so a screen would stop hardcoding its words — and this screen was
     * never wired to it. `coa panel` rendered `Consultan:`, `Cambian algo:`, `↑ N más arriba` and
     * `↓ N más abajo` to a human for two slices while the seam sat beside it with English defaults
     * nobody passed. A seam built for one of two consumers certifies only the consumer it reached
     * (greenhouse decisions/0310).
     */
    public function testEveryWordComesFromTheHostAndTheDefaultIsEnglish(): void
    {
        $mine = new ScreenLabels(reading: 'SOLO-LEEN', mutating: 'CAMBIAN', moreAbove: 'ARRIBA %d', moreBelow: 'ABAJO %d');

        // Both group headers: a window the whole list fits in.
        $headers = $this->scrolling(null)->render();
        self::assertStringContainsString('Read only:', $headers);
        self::assertStringContainsString('Change something:', $headers);

        $swapped = $this->scrolling($mine)->render();
        self::assertStringContainsString('SOLO-LEEN:', $swapped);
        self::assertStringContainsString('CAMBIAN:', $swapped);
        self::assertStringNotContainsString('Read only', $swapped, 'no default word survives a host vocabulary');
        self::assertStringNotContainsString('Change something', $swapped);

        // The scroll hint: a window the list does NOT fit in.
        self::assertMatchesRegularExpression('/↓ \\d+ more below/', $this->scrolling(null, 6, 12)->render());
        self::assertMatchesRegularExpression('/ABAJO \\d+/', $this->scrolling($mine, 6, 12)->render());
        self::assertStringNotContainsString('more below', $this->scrolling($mine, 6, 12)->render());

        // The empty state is a word too — it was already English, which is why it went unnoticed that
        // it was equally hardcoded.
        $bare = new OperationsScreen([], $this->container(), 60, 10, false, null, new ScreenLabels(noOperations: 'NADA'));
        self::assertStringContainsString('NADA', $bare->render());
    }

    /**
     * 🚨 NO NODE ID IN THIS SCREEN IS BUILT FROM A WORD — asserted at the SOURCE, and that is the point.
     *
     * The group header used to be the node's id as well: `new TuiNode('grupo:' . $grupo, …)` where
     * `$grupo` was the very string a person read. Translating the header would have silently renamed
     * the node, and a node id is what a key handler, a focus order and a diff address it by.
     *
     * This is a source assertion because the conflation is INVISIBLE from outside: `RetainedTuiLoop`
     * exposes `focusedId()` and `renderScreen()` and no node tree, ids are never rendered, and these
     * group nodes are not focusable — so no behavioural test could have caught it, before or after.
     * That is not a gap in the test; it is why the defect survived, and a guard that reads the source
     * is the only instrument that sees it.
     */
    public function testNoNodeIdIsBuiltFromAWord(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Tui/OperationsScreen.php');

        $offenders = [];
        foreach (explode("\n", $source) as $n => $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//')) {
                continue;
            }
            // The id is the FIRST argument of TuiNode. A `$this->labels->…` inside it means a word is
            // doing an identifier's job.
            if (preg_match('/new TuiNode\\(([^,]*)/', $line, $m) === 1 && str_contains($m[1], 'labels')) {
                $offenders[] = ($n + 1) . ': ' . $trimmed;
            }
        }

        self::assertSame([], $offenders, "a node id must be a fact, never copy:\n" . implode("\n", $offenders));
    }

    /**
     * A screen with both kinds of operation, sized by the caller.
     *
     * Showing both group headers and forcing a scroll hint are DIFFERENT windows — one needs the list
     * to fit, the other needs it not to — so each assertion asks for the size it needs instead of one
     * helper trying to satisfy both and silently satisfying neither.
     */
    private function scrolling(?ScreenLabels $labels, int $each = 2, int $height = 24): OperationsScreen
    {
        $ops = [];
        foreach (range(1, $each) as $i) {
            $ops[] = new Operation('read_' . $i, 'Answers ' . $i, static fn (array $in): array => ['ok' => true], inputSchema: ['type' => 'object', 'properties' => []], surfaces: ['tui']);
            $ops[] = new Operation('write_' . $i, 'Changes ' . $i, static fn (array $in): array => ['ok' => true], inputSchema: ['type' => 'object', 'properties' => []], surfaces: ['tui'], mutating: true);
        }

        return new OperationsScreen($ops, $this->container(), 74, $height, false, null, $labels);
    }

}

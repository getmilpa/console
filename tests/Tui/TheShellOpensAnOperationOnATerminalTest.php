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

use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Operation;
use Milpa\Console\Tui\OperationsScreen;
use Milpa\Live\Contracts\Tui\TerminalInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * `coa shell` runs the screen's LOOP against a terminal — not {@see OperationsScreen::press()}.
 *
 * Up to 0.22.3 the two were different paths: `press()` routed keys to the open operation, while the loop a
 * terminal drives only knew the list. On a real terminal Enter "opened" an operation nobody could see, the list
 * kept every key after it, and Escape closed the whole shell (greenhouse evidence/1060, t-0053). Every test here
 * drives the loop with the BYTES a terminal sends — `\t`, `\r`, `\x1b`, `\x03` — through {@see TerminalInterface},
 * which is what `coa shell` hands it.
 */
final class TheShellOpensAnOperationOnATerminalTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $ran = [];

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

    private function shell(): OperationsScreen
    {
        return new OperationsScreen([
            new Operation('plugins_list', 'Lists the plugins', static fn (array $i): array => ['ok' => true, 'total' => 3], inputSchema: ['type' => 'object', 'properties' => []]),
            // Declared read-only, so the form runs it: a call that demands a signature is refused there by design.
            new Operation('plugins_show', 'Shows a plugin', function (array $i): array {
                $this->ran[] = $i;

                return ['ok' => true, 'shown' => $i['name'] ?? '?'];
            }, inputSchema: ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'note' => ['type' => 'string']], 'required' => ['name']], effects: EffectProfile::readOnly()),
        ], $this->container(), 74, 20, false);
    }

    /**
     * Runs the shell's loop on a terminal that types each chunk in turn, and returns everything it painted.
     *
     * @param list<string> $typed one chunk per read, as a terminal delivers them
     */
    private function type(OperationsScreen $shell, array $typed, int $ticks = 80): string
    {
        $terminal = new class ($typed) implements TerminalInterface {
            public string $painted = '';

            /** @param list<string> $typed */
            public function __construct(private array $typed)
            {
            }

            public function start(callable $onInput, callable $onResize): void
            {
            }

            public function stop(): void
            {
            }

            public function write(string $data): void
            {
                $this->painted .= $data;
            }

            public function pollInput(): string
            {
                return array_shift($this->typed) ?? '';
            }

            public function atEndOfInput(): bool
            {
                return false;
            }

            public function columns(): int
            {
                return 74;
            }

            public function rows(): int
            {
                return 20;
            }

            public function moveBy(int $lines): void
            {
            }

            public function hideCursor(): void
            {
            }

            public function showCursor(): void
            {
            }

            public function clearLine(): void
            {
            }

            public function clearFromCursor(): void
            {
            }

            public function clearScreen(): void
            {
            }

            public function setTitle(string $title): void
            {
            }
        };

        $shell->loop()->runOn($terminal, idleMicroseconds: 0, maxTicks: $ticks, escapeTimeoutMicroseconds: 0);

        return $terminal->painted;
    }

    /** Enter opens the focused operation, and what the terminal shows becomes that operation's form. */
    public function testEnterOnATerminalOpensTheFormAndPaintsIt(): void
    {
        $shell = $this->shell();

        $painted = $this->type($shell, ["\t", "\r"]);

        self::assertSame('plugins_show', $shell->openOperation());
        self::assertStringContainsString('name *:', $painted, 'the form reached the terminal');
        self::assertStringContainsString('[Enter] correr', $shell->render());
    }

    /** With the form open, what someone types lands in its field, and Enter runs it — once, with those words. */
    public function testTheOpenFormTakesTheKeysAndRunsTheOperation(): void
    {
        $shell = $this->shell();

        $painted = $this->type($shell, ["\t", "\r", 'M', 'i', 'P', 'l', 'u', 'g', 'i', 'n', "\r"]);

        self::assertSame([['name' => 'MiPlugin']], $this->ran, 'the typed name, capitals kept, reached the handler');
        self::assertStringContainsString('MiPlugin', $painted);
        self::assertSame('plugins_show', $shell->openOperation(), 'the list never took those keys back');
    }

    /** Tab moves between the form's fields, not along the list behind it. */
    public function testTabMovesBetweenTheFieldsOfTheOpenForm(): void
    {
        $shell = $this->shell();

        $this->type($shell, ["\t", "\r", 'a', "\t", 'b', "\r"]);

        self::assertSame([['name' => 'a', 'note' => 'b']], $this->ran);
        self::assertSame('plugins_show', $shell->openOperation());
    }

    /** Driven key by key with nothing painted in between, the field that has the focus is still the one written. */
    public function testWithoutAFrameBetweenKeysTheFocusedFieldTakesTheText(): void
    {
        $shell = $this->shell();

        foreach (['tab', 'enter', 'a', 'tab', 'b', 'enter'] as $key) {
            $shell->press($key);
        }

        self::assertSame([['name' => 'a', 'note' => 'b']], $this->ran);
    }

    /** Escape closes the form and gives the keys back to the list — it does not close the shell. */
    public function testEscapeClosesTheFormAndTheShellKeepsRunning(): void
    {
        $shell = $this->shell();

        // Escape lands back on plugins_show; Tab twice wraps past the footer to plugins_list.
        $this->type($shell, ["\t", "\r", "\x1b", "\t", "\t", "\r"]);

        self::assertSame('plugins_list', $shell->openOperation(), 'after Escape the list moved and opened the other one');
        self::assertStringContainsString('[Enter] correr', $shell->render(), 'the other form is what shows');
    }

    /** Escape on the list still leaves the shell, as it always did: the loop stops. */
    public function testEscapeOnTheListLeavesTheShell(): void
    {
        $shell = $this->shell();

        $this->type($shell, ["\x1b", "\t", "\r"]);

        self::assertNull($shell->openOperation(), 'the keys after Escape were never read');
        self::assertSame([], $this->ran);
    }

    /** Ctrl-C leaves the shell from anywhere, form open or not. */
    public function testCtrlCLeavesTheShellEvenWithAFormOpen(): void
    {
        $shell = $this->shell();

        $this->type($shell, ["\t", "\r", "\x03", 'x', "\r"]);

        self::assertSame([], $this->ran, 'nothing typed after Ctrl-C ran');
    }

    /** `press()` and the terminal are the same path: the same keys leave the same screen. */
    public function testPressAndTheTerminalLeaveTheSameScreen(): void
    {
        $byTerminal = $this->shell();
        $this->type($byTerminal, ["\t", "\r", 'x', 'y']);

        $byPress = $this->shell();
        foreach (['tab', 'enter', 'x', 'y'] as $key) {
            $byPress->press($key);
        }

        self::assertSame($byPress->render(), $byTerminal->render());
    }
}

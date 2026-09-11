<?php

/**
 * This file is part of milpa/console.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/console
 */

declare(strict_types=1);

namespace Milpa\Console\Tests\Rendering;

use Milpa\Console\Rendering\PlainTextCliRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * 🚨 WHAT A HUMAN READS HERE IS ENGLISH, BECAUSE THIS PACKAGE HAS NO CATALOG TO SELECT ANYTHING ELSE.
 *
 * The framework's welcome page was reduced to one command under a heading that reads «Start here»,
 * and that command's answer came back with `ok: sí` on its first line. Two words into an
 * English-first framework's designated first contact, in a language the project's own rule says its
 * user surfaces do not default to — and English-first is stated there as an ADOPTABILITY decision,
 * so that first line is where the decision is paid or lost (greenhouse decisions/0138, measured in
 * decisions/0305 and fixed in 0306).
 *
 * Thirteen user-facing strings were Spanish: four in this renderer, six across the two TUI screens,
 * the executor fallback that lands in the ledger, and three exception messages.
 *
 * This is a GUARD and not a unit test, and it is deliberately narrow. `milpa/console` has NO
 * `I18n` directory — there is no catalog, no locale seam, nothing to select a second language with —
 * so an English default is the entire protection this surface has. Building the catalog is a
 * separate slice; until it exists, this test is what holds the default.
 *
 * IT DOES NOT POLICE COMMENTS OR IDENTIFIERS. Those are the code-language ratchet's subject
 * (`.milpa/evidence/code-language.tsv`), which measures whole packages and moves one direction only.
 * A guard that conflated the two would either fail on a docblock this slice never touched, or pass
 * while a rendered string went back to Spanish.
 */
#[CoversClass(PlainTextCliRenderer::class)]
final class TheCliSpeaksEnglishByDefaultTest extends TestCase
{
    /** The one a newcomer reads first: the verdict on the first line of the first screen. */
    public function testTheVerdictOnTheFirstLineIsEnglish(): void
    {
        $lines = (new PlainTextCliRenderer())->present(['ok' => true, 'off' => false]);

        self::assertSame(['ok: yes', 'off: no'], $lines);
    }

    /**
     * No rendered string in `src/` is Spanish.
     *
     * The words are the ones the thirteen actually used, so the check fails on a relapse rather than
     * on a false positive: a Spanish word that never appeared in a rendered string here would be
     * caught by the ratchet, not by this.
     */
    public function testNoRenderedStringInTheSourceIsSpanish(): void
    {
        $spanish = '/(Opciones|obligatori|opcional|Muta y exige|córrela|Córrela|exige una firma'
            . '|muta y exige|cambia algo|ninguna operación|desconocido|La operación|no cableó'
            . '|Registra una política|contribuyó|no vacío|sin esquema|\bsí\b)/u';

        $offenders = [];
        foreach (self::phpFiles(\dirname(__DIR__, 2) . '/src') as $file) {
            foreach (explode("\n", (string) file_get_contents($file)) as $n => $line) {
                $trimmed = ltrim($line);
                // Comments and docblocks are the ratchet's subject, not this guard's.
                if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                    continue;
                }
                if (!preg_match('/[\'"]/', $line)) {
                    continue;
                }
                foreach (self::quoted($line) as $text) {
                    // An ARRAY KEY is not something a human reads — `'obligatorio' => true` and
                    // `$field['obligatorio']` are identifiers that happen to be quoted, and they are
                    // the ratchet's subject like any other identifier. A guard that flagged them would
                    // fail on code this slice never touched.
                    if (preg_match('/[\'"]' . preg_quote($text, '/') . '[\'"]\s*(=>|\])/', $line) === 1) {
                        continue;
                    }
                    if (preg_match($spanish, $text) === 1) {
                        $offenders[] = basename($file) . ':' . ($n + 1) . '  ' . $text;
                    }
                }
            }
        }

        self::assertSame([], $offenders, "a string a human reads must be English by default:\n" . implode("\n", $offenders));
    }

    /**
     * Every quoted literal on a line, single or double.
     *
     * @return list<string>
     */
    private static function quoted(string $line): array
    {
        preg_match_all('/\'([^\']{2,200})\'|"([^"]{2,200})"/', $line, $found, \PREG_SET_ORDER);

        return array_map(static fn (array $m): string => $m[2] ?? $m[1], $found);
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $root): array
    {
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $found[] = $file->getPathname();
            }
        }

        return $found;
    }
}

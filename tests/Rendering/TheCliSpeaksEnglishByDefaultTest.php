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
     * The words are the ones the fourteen actually used, so the check fails on a relapse rather than
     * on a false positive: a Spanish word that never appeared in a rendered string here would be
     * caught by the ratchet, not by this.
     *
     * 🚨 AND IT IS CASE-INSENSITIVE, because the first version was not and a probe caught it.
     * Probing this guard with `'la operación fue detenida'` — the lowercase form of a sentence this
     * very slice had just translated — passed clean: the pattern held `La operación` with a capital,
     * so one letter of case was the whole hole. A guard whose word list is exact in case certifies
     * every other capitalisation of the same word, and a relapse rarely arrives in the same sentence
     * twice.
     *
     * The lesson is not about this regex: **a guard is worth exactly one probe, and the probe has to
     * differ from the thing the guard was written against.** Three sibling guards in app-runtime,
     * admin and agent-workspace were probed the same way in the same session and held, which is why
     * this one's failure was visible at all (greenhouse decisions/0306).
     *
     * 🚨 AND THE SAME HOLE OPENED AGAIN ONE LEVEL DOWN, IN THIS GUARD, THE DAY IT WAS WRITTEN. It went
     * green while `coa panel` rendered FOUR Spanish strings to a human — `Cambian algo`, `Consultan`,
     * `↑ N más arriba`, `↓ N más abajo` — because the list held `cambia algo` and a conjugation is not
     * a capitalisation. `/ui` fixed the case and certified every other inflection of the same verb.
     * The probe below is therefore a CONJUGATION and a word the list never held, not another case
     * variant: the second probe has to differ from the first as much as the first differed from the
     * pattern. Word lists lose to morphology; the accented-character rule is the one that does not
     * (greenhouse decisions/0310).
     */
    public function testNoRenderedStringInTheSourceIsSpanish(): void
    {
        // Two rules, and the second is the one that scales. A WORD LIST catches what somebody thought
        // of; ANY WORD CARRYING A SPANISH ACCENT catches what nobody did — `más`, `operación`,
        // `política`, `está`, and every inflection of them, without anyone maintaining a list. The
        // list stays for the accentless offenders a human reads (`Consultan`, `cambia`, `Opciones`),
        // and it is now written as STEMS so a conjugation cannot slip past: `cambia|cambian` became
        // `cambia`, matched anywhere in the quoted text.
        // 🚨 A STEM THAT MATCHES ENGLISH IS WORSE THAN NO STEM. The first widening used `contribu`,
        // which flagged the English sentence «Provider … contributed an invalid section» — a guard that
        // cries wolf gets its list trimmed by the next person, and the trim is where the real hole
        // comes back. So the list holds ONLY words that carry no accent and cannot occur in English,
        // and everything accented is left to the second rule, which needs no maintainer.
        $spanish = '/(Opciones|obligatori|opcional|Muta y exige|cambia|Consulta|exige una firma'
            . '|desconocido|sin esquema|más arriba|más abajo|fuera de gram|operacion|declarad'
            // Rule two: any Spanish accent inside a quoted string. `más`, `operación`, `política`,
            // `sección`, `único` and every inflection of them, without anyone remembering a list.
            . '|[áéíóúñ¿¡]'
            // Rule three, the one that needed no maintainer either: Spanish FUNCTION words, whole-word.
            // Accents catch inflected content words; this catches the accentless sentence around them —
            // `no es un path local absoluto` has neither an accent nor a listed noun, and it sat two
            // lines from strings this guard did flag. English words are excluded on purpose: `sin` is
            // one, and so are `a`, `e`, `o` — a rule that cries wolf gets trimmed, and the trim is
            // where the hole comes back. Measured across this package's whole `src/` when adopted:
            // 5 hits, all real, 0 false positives.
            //
            // WHAT THESE THREE RULES STILL MISS, said out loud so the next reader does not trust them
            // further than they reach: accentless Spanish built only from content words, with no
            // function word and nothing on the list — `Sin operaciones declaradas` was missed until
            // `operacion` and `declarad` were added (both safe: English spells them with a `t` and an
            // `e`). There is no rule short of language detection that closes this, so the guard is a
            // ratchet, not a proof.
            . '|\b(el|la|los|las|un|una|unos|unas|del|que|para|por|como|cada|entre|todos|todas'
            . '|est[aáé]|ning[uú]n|ninguna|no es|no tiene|ya estaba)\b'
            . ')/ui';

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
                    //
                    // 🚨 AND THIS EXEMPTION WAS THE WHOLE HOLE, NOT THE WORD LIST. It used to read
                    // `['\"]…['\"]\s*(=>|\])` — it looked only at what FOLLOWS the string, so a string
                    // that CLOSES its array matched `\]` and was exempted as if it were a key. Every
                    // `props: ['text' => 'algo']` in this package was therefore invisible, which is
                    // exactly the shape of the four Spanish strings `coa panel` rendered to a human for
                    // two slices. The word list was a red herring: widening it changed nothing, and the
                    // probe that proved it was a mutation the widened list DID contain, still passing.
                    //
                    // A key is a string FOLLOWED by `=>`. A subscript is a string PRECEDED by `[`. A
                    // value is a string PRECEDED by `=>`, `(` or `,` — and a value is what a human
                    // reads. Deciding by what comes BEFORE is what separates the three
                    // (greenhouse decisions/0310).
                    $quoted = '[\'"]' . preg_quote($text, '/') . '[\'"]';
                    $isKey = preg_match('/' . $quoted . '\s*=>/', $line) === 1;
                    $isSubscript = preg_match('/\[\s*' . $quoted . '\s*\]/', $line) === 1;
                    // A NODE'S ID is the third thing a machine reads and a person does not. `new
                    // TuiNode('operacion:' . $op->name, …)` is an address; the words live in `props`.
                    // It is exempt for the same reason a key is — and the companion assertion in
                    // OperationsScreenTest keeps ids from BEING words in the first place, which is the
                    // half this exemption depends on (greenhouse decisions/0310).
                    $isNodeId = preg_match('/new TuiNode\(\s*' . $quoted . '/', $line) === 1;
                    if ($isKey || $isSubscript || $isNodeId) {
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

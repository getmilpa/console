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

namespace Milpa\Console\Tui;

/**
 * The words a terminal dashboard shows — English by default, replaceable by the host.
 *
 * {@see ConsoleScreen} and {@see OperationsScreen} decide STRUCTURE, never words: which section is
 * focused, what is a table, where the status bar goes, how deep a row is indented. The words are a
 * different authority, and they belong to whoever has a language.
 *
 * 🚨 THE SECOND SCREEN WAS NOT WIRED TO THIS FOR TWO SLICES, and the sentence below — «until this
 * existed the screen said them in Spanish, hardcoded» — stayed true of it the whole time. `coa panel`
 * rendered `Consultan:`, `Cambian algo:`, `↑ N más arriba` and `↓ N más abajo` to a human while this
 * class sat beside it with English defaults nobody passed it. A seam built for one of two consumers
 * is a seam that certifies the consumer it reached (greenhouse decisions/0310).
 * Until this existed the screen said them in Spanish, hardcoded, so a panel serving an English app spoke
 * Spanish and nothing could change it — the docblock claimed the words were the host's and the code kept
 * them.
 *
 * English is the default because the framework is English-first for adoptability; a host with a message
 * catalog passes its own translations and the same screen answers in any locale.
 */
final readonly class ScreenLabels
{
    public function __construct(
        /** Column header for a state key. */
        public string $field = 'Field',
        /** Column header for a state value. */
        public string $value = 'Value',
        /** Shown when no section exposes inspectable state. */
        public string $empty = 'No section exposes inspectable state.',
        /** The keys the status bar teaches, at its right edge. */
        public string $keys = 'tab · # · q quit',
        /**
         * The group header over operations that only answer — they run nothing and change nothing.
         *
         * A header, not a sentence: the screen adds its own punctuation and indentation, because those
         * are structure. Whoever translates this owns only the word.
         */
        public string $reading = 'Read only',
        /** The group header over operations that change something — the family's own wording for it. */
        public string $mutating = 'Change something',
        /**
         * The hint that the list scrolls further up — `%d` is how many rows are above the window.
         *
         * A placeholder rather than a word plus a number, because the order of the two is a property of
         * the language, not of the screen: a host translating this decides where the count goes.
         */
        public string $moreAbove = '↑ %d more above',
        /** The same, downward — `%d` is how many rows are below the window. */
        public string $moreBelow = '↓ %d more below',
        /** Shown when the app declares no operation this surface may offer. */
        public string $noOperations = 'This app declares no operation for this surface.',
        /** What the status bar shows before the section title. */
        public string $brand = 'milpa',
    ) {
    }
}

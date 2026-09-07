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
 * {@see ConsoleScreen} decides STRUCTURE, never words: which section is focused, what is a table, where
 * the status bar goes. The words are a different authority, and they belong to whoever has a language.
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
        /** What the status bar shows before the section title. */
        public string $brand = 'milpa',
    ) {
    }
}

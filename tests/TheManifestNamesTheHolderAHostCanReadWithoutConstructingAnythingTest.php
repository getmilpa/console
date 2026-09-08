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

namespace Milpa\Console\Tests;

use Milpa\Console\Events\ConsoleEvents;
use Milpa\Interfaces\Event\DeclaresEvents;
use Milpa\Interfaces\Event\EventDeclaration;
use PHPUnit\Framework\TestCase;

/**
 * The falsifier of greenhouse decisions/0228 second slice for this package: the manifest names the
 * declarations holder, so a host can list this package's events without constructing its emitters.
 *
 * An emitter declares when it is BUILT, and a CLI process never builds most of them — measured on
 * cattle, `events:catalogue` answered seven of the family's twenty-four (greenhouse evidence/0567).
 * The manifest is how the declaration of an emitter nobody constructed reaches the dispatcher: a
 * host reads `extra.milpa.events` from `vendor/composer/installed.json`, resolves each class name,
 * and declares on the emitter's behalf.
 *
 * The instrument reads the package's OWN `composer.json` FROM DISK — never a constant, never a
 * reading of the prose — and drives the class named THERE. A typo, a rename, or a holder that stops
 * being a holder goes red here instead of turning into an app that silently lists nothing.
 */
final class TheManifestNamesTheHolderAHostCanReadWithoutConstructingAnythingTest extends TestCase
{
    /**
     * The holders this package promises to name. Hardcoded on purpose: the manifest cannot be the
     * oracle for itself.
     *
     * @var list<class-string>
     */
    private const array EXPECTED_HOLDERS = [ConsoleEvents::class];

    public function test_the_manifest_lists_exactly_the_holders_of_this_package(): void
    {
        self::assertSame(self::EXPECTED_HOLDERS, $this->holdersNamedInTheManifest());
    }

    public function test_every_class_the_manifest_names_exists_and_is_a_holder(): void
    {
        foreach ($this->holdersNamedInTheManifest() as $holder) {
            self::assertTrue(class_exists($holder), "the manifest names «{$holder}» and no such class exists");
            self::assertTrue(
                is_a($holder, DeclaresEvents::class, true),
                "the manifest names «{$holder}» and it does not implement " . DeclaresEvents::class,
            );
        }
    }

    public function test_the_class_named_in_the_manifest_declares_the_same_events_the_holder_does(): void
    {
        $fromTheManifest = [];
        foreach ($this->holdersNamedInTheManifest() as $holder) {
            /** @var list<EventDeclaration> $declarations */
            $declarations = $holder::declarations();
            foreach ($declarations as $declaration) {
                $fromTheManifest[] = $declaration->name;
            }
        }

        $fromTheHolder = array_map(
            static fn (EventDeclaration $declaration): string => $declaration->name,
            ConsoleEvents::declarations(),
        );

        self::assertNotSame([], $fromTheHolder, 'the holder declares at least one event');
        self::assertSame($fromTheHolder, $fromTheManifest, 'the manifest reaches the same declarations the emitter would');
    }

    /**
     * CONTROL: the reader really reads the file, so a name that is not in it is not reported as
     * being in it — the same lookup applied to a manifest of this shape with one letter dropped
     * finds a class that does not exist.
     */
    public function test_a_holder_name_with_a_letter_dropped_is_not_a_class(): void
    {
        $typo = substr(ConsoleEvents::class, 0, -1);

        self::assertNotContains($typo, $this->holdersNamedInTheManifest());
        self::assertFalse(class_exists($typo), 'the control name must not resolve, or the falsifier could not go red');
    }

    /**
     * The class names under `extra.milpa.events` in this package's own manifest, read from disk.
     *
     * @return list<class-string>
     */
    private function holdersNamedInTheManifest(): array
    {
        $path = \dirname(__DIR__) . '/composer.json';
        $raw = file_get_contents($path);
        self::assertIsString($raw, "the package manifest is not readable at {$path}");

        $manifest = json_decode($raw, true);
        self::assertIsArray($manifest, 'the package manifest is not a JSON object');

        $events = $manifest['extra']['milpa']['events'] ?? null;
        self::assertIsArray($events, 'the manifest has no `extra.milpa.events` list');
        self::assertSame(array_values($events), $events, '`extra.milpa.events` must be a LIST of class names');

        /** @var list<class-string> $events */
        return $events;
    }
}

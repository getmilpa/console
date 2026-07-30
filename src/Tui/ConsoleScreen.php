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

use Milpa\Live\Tui\NodeRenderers\BoxRenderer;
use Milpa\Live\Tui\NodeRenderers\DataTableRenderer;
use Milpa\Live\Tui\NodeRenderers\StatusBarRenderer;
use Milpa\Live\Tui\NodeRenderers\TextRenderer;
use Milpa\Live\Tui\RetainedTuiLoop;
use Milpa\Live\Tui\RetainedTuiRenderer;
use Milpa\Live\Tui\SimpleTuiLayoutEngine;
use Milpa\Live\Tui\State\StateToNode;
use Milpa\Live\Tui\TuiNodeRendererRegistry;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Milpa\Console\State\InspectableSections;

/**
 * El dashboard navegable: el mismo estado por sección que el shell web, con la
 * sección enfocada como unidad de navegación.
 *
 * La navegación no la implementa el host. El tier ya tiene foco y ya mueve el
 * foco con Tab; lo único que hace falta es que el orden de foco SEAN las
 * secciones y que el árbol se arme para la que está enfocada. Lo que el host sí
 * agrega son los atajos que solo tienen sentido acá —un dígito por sección, y
 * las flechas como sinónimo de Tab— y las palabras de la barra de estado, que
 * son suyas porque tiene un idioma (ADR-0027).
 *
 * Vive aparte de {@see \Milpa\Admin\Commands\TuiCommand} para
 * que la navegación se pueda probar sin una terminal: acá no hay `stty`, no hay
 * `stream_isatty` y no se escribe una sola secuencia a mano. Esa compuerta es un
 * hecho del DESTINO y vive en el comando (ADR-0025).
 */
final class ConsoleScreen
{
    private readonly RetainedTuiLoop $loop;

    public function __construct(
        private readonly InspectableSections $sections,
        int $width = 80,
        int $height = 24,
        bool $ansi = true,
        ?string $initialSection = null,
    ) {
        $ids = $this->sections->ids();
        $first = $initialSection !== null && \in_array($initialSection, $ids, true)
            ? $initialSection
            : ($ids[0] ?? '');

        $this->loop = new RetainedTuiLoop(
            new RetainedTuiRenderer(new SimpleTuiLayoutEngine(), $this->renderers()),
            fn (): TuiNode => $this->tree(),
            $ids,
            $first,
            $width,
            $height,
            $ansi,
            fn (string $key, RetainedTuiLoop $loop): bool => $this->handleKey($key, $loop),
        );
    }

    /** El loop armado, para correrlo contra una terminal. */
    public function loop(): RetainedTuiLoop
    {
        return $this->loop;
    }

    /** El id de la sección que se está mostrando. */
    public function currentSectionId(): string
    {
        return $this->loop->focusedId();
    }

    /** La pantalla completa como texto, sin necesitar una terminal. */
    public function render(): string
    {
        return $this->loop->renderScreen();
    }

    /** Manda una tecla, como si alguien la hubiera tecleado. */
    public function press(string $key): bool
    {
        return $this->loop->dispatchKey($key);
    }

    /**
     * Los atajos que solo existen en este dashboard.
     *
     * Tab y shift+Tab ya los resuelve el tier, así que no se reimplementan: un
     * segundo camino al mismo movimiento es un segundo camino que se puede
     * desincronizar. Las flechas son sinónimo porque una lista horizontal de
     * secciones se navega con flechas antes que con Tab, y los dígitos porque
     * llegar a la cuarta sección tabulando cuatro veces no es navegar.
     *
     * Las flechas quedan cableadas pero NO anunciadas en la barra: hasta
     * `milpa/live-tui` v0.2.3 los loops normalizaban las teclas con una tabla
     * propia que conocía ↑ y ↓ pero no ← ni →, así que sobre esa versión la
     * flecha nunca llega hasta acá. Anunciar una tecla que no responde es peor
     * que no tenerla. Cuando el host suba a la versión con el arreglo, la barra
     * las nombra —y es esta línea la que cambia—.
     */
    private function handleKey(string $key, RetainedTuiLoop $loop): bool
    {
        if ($key === 'right' || $key === 'l') {
            $loop->focusNext();

            return true;
        }

        if ($key === 'left' || $key === 'h') {
            $loop->focusPrevious();

            return true;
        }

        if (preg_match('/^[1-9]$/', $key) === 1) {
            $ids = $this->sections->ids();
            $target = $ids[(int) $key - 1] ?? null;
            if ($target !== null) {
                $loop->focus($target);
            }

            // Consumida incluso cuando el dígito no nombra ninguna sección: la
            // alternativa es que caiga al "Unhandled" del tier y le diga a la
            // persona que su tecla no existe, cuando lo que no existe es esa
            // sección.
            return true;
        }

        return false;
    }

    /**
     * El árbol de la pantalla: la sección enfocada mapeada por el tier, más la
     * barra de estado que el host sí decide.
     */
    private function tree(): TuiNode
    {
        $current = $this->sections->find($this->loop->focusedId());

        if ($current === null) {
            return new TuiNode('root', 'box', children: [
                new TuiNode('vacio', 'text', props: ['text' => 'Ninguna sección expone estado inspectable.']),
                $this->statusBar('—'),
            ]);
        }

        // El estado se pide en CADA frame, no una vez al arrancar: un dashboard
        // que congela lo que leyó al abrirse es una captura de pantalla.
        $section = (new StateToNode('Campo', 'Valor'))->map($current['id'], $current['provider']->state());

        return new TuiNode('root', 'box', children: [
            ...$section->children,
            $this->statusBar($current['title']),
        ]);
    }

    /**
     * La barra: qué se está viendo, qué más hay y cómo llegar.
     *
     * Lleva la lista completa con la actual marcada porque un dashboard que no
     * dice qué más hay no se navega — se adivina.
     */
    private function statusBar(string $title): TuiNode
    {
        $current = $this->loop->focusedId();
        $etiquetas = [];
        foreach ($this->sections->all() as $i => $section) {
            $numero = $i + 1;
            $etiquetas[] = $section['id'] === $current
                ? "[{$numero} {$section['id']}]"
                : "{$numero} {$section['id']}";
        }

        return new TuiNode('status', 'status-bar', props: [
            'height' => 1,
            'left' => 'milpa · ' . $title,
            'right' => ($etiquetas === [] ? '' : implode('  ', $etiquetas) . '  ·  ') . 'tab · nº · q salir',
        ]);
    }

    private function renderers(): TuiNodeRendererRegistry
    {
        $registry = new TuiNodeRendererRegistry();
        $registry->register(new DataTableRenderer());
        $registry->register(new StatusBarRenderer());
        $registry->register(new TextRenderer());
        $registry->register(new BoxRenderer());

        return $registry;
    }
}

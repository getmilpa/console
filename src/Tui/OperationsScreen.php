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

use Milpa\Command\Operation;
use Milpa\Live\Tui\NodeRenderers\BoxRenderer;
use Milpa\Live\Tui\NodeRenderers\TextRenderer;
use Milpa\Live\Tui\RetainedTuiLoop;
use Milpa\Live\Tui\RetainedTuiRenderer;
use Milpa\Live\Tui\SimpleTuiLayoutEngine;
use Milpa\Live\Tui\TuiNodeRendererRegistry;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Psr\Container\ContainerInterface;

/**
 * Todo lo que esta app sabe hacer, navegable — y cualquiera de esas cosas, corrible.
 *
 * Es el shell: una lista de operaciones agrupadas por si consultan o si cambian algo, y al entrar en
 * una, {@see OperationScreen} para llenarla y correrla. La lista se DERIVA de los átomos, igual que
 * la ayuda de `coa`: una pantalla escrita a mano sería el primer archivo que miente cuando alguien
 * instala un plugin.
 *
 * ── UNA PANTALLA A LA VEZ ───────────────────────────────────────────────────────────────────────
 *
 * No hay ventanas ni paneles simultáneos: se está en la lista o se está en una operación. Es una
 * decisión y no una limitación — un TUI que muestra todo a la vez obliga a leer todo a la vez, y lo
 * que alguien quiere aquí es contestar una pregunta y salir.
 */
final class OperationsScreen
{
    private readonly RetainedTuiLoop $loop;

    /** @var list<Operation> */
    private array $operaciones;

    private ?OperationScreen $abierta = null;

    private ?string $nombreAbierta = null;

    /**
     * @param iterable<Operation> $operaciones
     */
    public function __construct(
        iterable $operaciones,
        private readonly ContainerInterface $container,
        private readonly int $width = 80,
        private readonly int $height = 24,
        private readonly bool $ansi = true,
        private readonly ?\Milpa\Interfaces\Event\MilpaEventDispatcherInterface $dispatcher = null,
    ) {
        // Consultan primero y cambian algo después, que es el orden en que alguien decide: se mira
        // antes de tocar. Dentro de cada grupo, alfabético — un orden que no cambia entre corridas.
        $lista = [];
        foreach ($operaciones as $operacion) {
            if ($operacion->supportsSurface('tui')) {
                $lista[] = $operacion;
            }
        }
        usort($lista, static function (Operation $a, Operation $b): int {
            return [$a->mutating, $a->name] <=> [$b->mutating, $b->name];
        });
        $this->operaciones = $lista;

        $ids = array_map(static fn (Operation $op): string => 'op:' . $op->name, $this->operaciones);
        $ids[] = 'salir';

        $this->loop = new RetainedTuiLoop(
            new RetainedTuiRenderer(new SimpleTuiLayoutEngine(), self::renderers()),
            fn (): TuiNode => $this->tree(),
            $ids,
            $ids[0],
            $width,
            $height,
            $ansi,
            fn (string $key, RetainedTuiLoop $loop): bool => $this->handleKey($key, $loop),
            // Sin `q` entre las teclas de salida: el default del tier la incluye —lo que un dashboard
            // quiere— y aquí se teclea texto. Con ella, una `q` escrita en un campo cerraba la
            // pantalla en vez de escribirse, y no había forma de teclear «query» ni «plugin».
            quitKeys: ['escape', 'ctrl+c'],
        );
    }

    private static function renderers(): TuiNodeRendererRegistry
    {
        $registry = new TuiNodeRendererRegistry();
        $registry->register(new TextRenderer());
        $registry->register(new BoxRenderer());

        return $registry;
    }

    /** El loop armado, para correrlo contra una terminal. */
    public function loop(): RetainedTuiLoop
    {
        return $this->loop;
    }

    /** La pantalla que se está viendo: la lista, o la operación abierta. */
    public function render(): string
    {
        return $this->abierta?->render() ?? $this->loop->renderScreen();
    }

    /** Manda una tecla a donde corresponda: a la operación abierta, o a la lista. */
    public function press(string $key): bool
    {
        if ($this->abierta !== null) {
            // Escape cierra y vuelve a la lista. Sin una salida clara, un TUI que entra en algo es
            // una trampa — y ctrl+c mata el proceso en vez de cerrar la pantalla.
            if ($key === 'escape') {
                $this->abierta = null;
                $this->nombreAbierta = null;

                return true;
            }

            return $this->abierta->press($key);
        }

        return $this->loop->dispatchKey($key);
    }

    /** El nombre de la operación abierta, o `null` si se está en la lista. */
    public function openOperation(): ?string
    {
        return $this->nombreAbierta;
    }

    /**
     * Las operaciones que este shell ofrece, ya ordenadas.
     *
     * @return list<Operation>
     */
    public function operations(): array
    {
        return $this->operaciones;
    }

    /** Enter abre la operación enfocada. El movimiento lo resuelve el tier. */
    private function handleKey(string $key, RetainedTuiLoop $loop): bool
    {
        if ($key !== 'enter') {
            return false;
        }

        $enfocado = $loop->focusedId();
        foreach ($this->operaciones as $operacion) {
            if ('op:' . $operacion->name === $enfocado) {
                $this->abierta = new OperationScreen($operacion, $this->container, $this->width, $this->height, $this->ansi, dispatcher: $this->dispatcher);
                $this->nombreAbierta = $operacion->name;

                return true;
            }
        }

        return false;
    }

    private function tree(): TuiNode
    {
        $enfocado = $this->loop->focusedId();
        $hijos = [];

        $grupoAnterior = null;
        foreach ($this->operaciones as $operacion) {
            $grupo = $operacion->mutating ? 'Cambian algo' : 'Consultan';
            if ($grupo !== $grupoAnterior) {
                $hijos[] = new TuiNode('grupo:' . $grupo, 'text', props: ['text' => $grupo . ':']);
                $grupoAnterior = $grupo;
            }

            $id = 'op:' . $operacion->name;
            $firma = $operacion->requiresConfirmation ? ' ⚠' : '';
            $hijos[] = new TuiNode($id, 'text', props: [
                'text' => ($id === $enfocado ? '  ▸ ' : '    ') . $operacion->name . $firma . '  — ' . $operacion->description,
            ]);
        }

        if ($hijos === []) {
            $hijos[] = new TuiNode('vacio', 'text', props: ['text' => 'Esta app no declara ninguna operación para esta superficie.']);
        }

        $hijos[] = new TuiNode('salir', 'text', props: [
            'text' => ($enfocado === 'salir' ? '  ▸ ' : '    ') . '[Enter] abrir · [Tab] siguiente · [Esc] volver · ⚠ = exige firma',
        ]);

        return new TuiNode('root', 'box', props: ['title' => 'coa · shell'], children: $hijos);
    }
}

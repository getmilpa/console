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
use Milpa\Console\Consent;
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

    /** The words this screen shows — English by default, the host's when it has a language. */
    private readonly ScreenLabels $labels;

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
        ?ScreenLabels $labels = null,
    ) {
        $this->labels = $labels ?? new ScreenLabels();
        // Reading first and changing second, which is the order in which someone decides: you look
        // before you touch. Inside each group, alphabetical — an order that does not move between runs.
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
            // Without Escape either: a loop stops on a quit key before any screen hears it, and Escape means «back»
            // while a form is open. {@see self::handleKey()} stops the loop on Escape only from the list.
            quitKeys: ['ctrl+c'],
        );
        $this->ids = $ids;
    }

    /** @var list<string> the list's focus order, given back when a form closes */
    private array $ids;

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
        return $this->loop->renderScreen();
    }

    /**
     * Manda una tecla, como si alguien la hubiera tecleado — by the same loop a terminal drives.
     *
     * 🚨 ONE PATH. Up to 0.22.3 this method routed keys to the open operation itself, and the loop `coa shell` runs
     * on a terminal did not: Enter "opened" a form the terminal never painted, and the list kept every key after it
     * (greenhouse evidence/1060, t-0053). Every test drove this method, so every test passed. Now this method only
     * hands the key to the loop, and the loop is what knows a form is open.
     */
    public function press(string $key): bool
    {
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

    /**
     * With a form open, every key is the form's; on the list, Enter opens the focused operation and Escape leaves.
     *
     * The form keeps its own loop for its fields and its run, and this loop only lends it the terminal: the form's
     * fields become this loop's focus order (Tab never reaches a screen — the loop moves focus first), the form is
     * told which field has the focus, and it receives the key exactly as it arrived.
     */
    private function handleKey(string $key, RetainedTuiLoop $loop): bool
    {
        if ($this->abierta !== null) {
            // Escape cierra y vuelve a la lista. Sin una salida clara, un TUI que entra en algo es
            // una trampa — y ctrl+c mata el proceso en vez de cerrar la pantalla.
            if ($key === 'escape') {
                $this->cerrar($loop);

                return true;
            }
            $this->abierta->loop()->focus($loop->focusedId());
            $this->abierta->press($loop->lastRawKey());

            return true;
        }

        if ($key === 'escape') {
            // The loop has no stop of its own: its quit key is the one way to end it.
            $loop->dispatchKey('ctrl+c');

            return true;
        }

        if ($key !== 'enter') {
            return false;
        }

        $enfocado = $loop->focusedId();
        foreach ($this->operaciones as $operacion) {
            if ('op:' . $operacion->name === $enfocado) {
                $this->abierta = new OperationScreen($operacion, $this->container, $this->width, $this->height, $this->ansi, dispatcher: $this->dispatcher);
                $this->nombreAbierta = $operacion->name;
                $orden = $this->abierta->focusOrder();
                $loop->setFocusOrder($orden);
                $loop->focus($orden[0]);

                return true;
            }
        }

        return false;
    }

    /** Back to the list, with the focus on the operation that was open. */
    private function cerrar(RetainedTuiLoop $loop): void
    {
        $nombre = $this->nombreAbierta;
        $this->abierta = null;
        $this->nombreAbierta = null;
        $loop->setFocusOrder($this->ids);
        $loop->focus('op:' . $nombre);
    }

    private function tree(): TuiNode
    {
        if ($this->abierta !== null) {
            $this->abierta->loop()->focus($this->loop->focusedId());

            return $this->abierta->node();
        }

        $enfocado = $this->loop->focusedId();
        $hijos = [];

        // ── LA VENTANA, Y POR QUÉ EXISTE ────────────────────────────────────────────────────────
        //
        // Esta pantalla metía TODAS las operaciones en una caja de alto fijo. Mientras el catálogo
        // cupo, se vio bien; el día que lo pasó por una sola operación el menú salió **en blanco** —
        // marco, encabezado y pie, y nada en medio—. No truncó: desapareció.
        //
        // Un desbordamiento que se ve como una lista vacía es peor que uno que se ve feo: dice «esta
        // app no tiene operaciones» y eso es falso. Y estaba a UNA operación de pasar, así que iba a
        // pasar el día que alguien instalara una capacidad más.
        //
        // La ventana sigue al foco y DICE cuántas quedan fuera. Que sobre trabajo no se esconde:
        // ocultar en silencio es la misma sustitución de siempre, la parte por el todo.
        $porVer = max(3, $this->height - 6);
        $indice = 0;
        foreach ($this->operaciones as $i => $operacion) {
            if ('op:' . $operacion->name === $enfocado) {
                $indice = $i;
            }
        }
        $total = \count($this->operaciones);
        $desde = max(0, min($indice - intdiv($porVer, 2), $total - $porVer));
        $hasta = min($total, $desde + $porVer);

        if ($desde > 0) {
            $hijos[] = new TuiNode('antes', 'text', props: ['text' => '    ' . \sprintf($this->labels->moreAbove, $desde)]);
        }

        $grupoAnterior = null;
        foreach (\array_slice($this->operaciones, $desde, $hasta - $desde) as $operacion) {
            // 🚨 THE ID AND THE WORD ARE TWO THINGS. One string used to be both the node's id and the
            // header a person reads, so translating the header silently renamed the node — and a node
            // id is what a test, a key handler and a diff all address it by. The id is now the FACT
            // (`mutating` / `reading`); the word is the label's (greenhouse decisions/0310).
            $grupo = $operacion->mutating ? 'mutating' : 'reading';
            if ($grupo !== $grupoAnterior) {
                $palabra = $operacion->mutating ? $this->labels->mutating : $this->labels->reading;
                $hijos[] = new TuiNode('grupo:' . $grupo, 'text', props: ['text' => $palabra . ':']);
                $grupoAnterior = $grupo;
            }

            $id = 'op:' . $operacion->name;
            $firma = Consent::demanded($operacion) ? ' ⚠' : '';
            $hijos[] = new TuiNode($id, 'text', props: [
                'text' => ($id === $enfocado ? '  ▸ ' : '    ') . $operacion->name . $firma . '  — ' . $operacion->description,
            ]);
        }

        if ($hasta < $total) {
            $hijos[] = new TuiNode('despues', 'text', props: ['text' => '    ' . \sprintf($this->labels->moreBelow, $total - $hasta)]);
        }

        if ($hijos === []) {
            $hijos[] = new TuiNode('vacio', 'text', props: ['text' => $this->labels->noOperations]);
        }

        $hijos[] = new TuiNode('salir', 'text', props: [
            'text' => ($enfocado === 'salir' ? '  ▸ ' : '    ') . '[Enter] open · [Tab] next · [Esc] back · ⚠ = needs a signature',
        ]);

        return new TuiNode('root', 'box', props: ['title' => 'coa · shell'], children: $hijos);
    }
}

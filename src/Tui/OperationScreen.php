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
use Milpa\Console\Rendering\CliRenderer;
use Milpa\Console\Rendering\PlainTextCliRenderer;
use Milpa\Console\SchemaCoercer;
use Milpa\Console\SchemaCoercionException;
use Milpa\Console\TuiProjector;
use Milpa\Live\Tui\NodeRenderers\BoxRenderer;
use Milpa\Live\Tui\NodeRenderers\TextRenderer;
use Milpa\Live\Tui\RetainedTuiLoop;
use Milpa\Live\Tui\RetainedTuiRenderer;
use Milpa\Live\Tui\SimpleTuiLayoutEngine;
use Milpa\Live\Tui\TuiNodeRendererRegistry;
use Milpa\Live\ValueObjects\Tui\TuiNode;
use Psr\Container\ContainerInterface;

/**
 * Una operación que se puede LLENAR y CORRER en la terminal.
 *
 * ── LA MITAD QUE FALTABA ────────────────────────────────────────────────────────────────────────
 *
 * {@see TuiProjector} ya producía el árbol de una operación —panel, un campo por propiedad del
 * esquema, el aviso de firma— y ahí se detenía: era una pantalla que se leía. Nadie capturaba lo que
 * alguien escribía en esos campos ni llamaba al handler, así que la superficie `tui` existía como
 * proyección y no como superficie. Esto es la otra mitad de ADR-0035 para esta superficie: el
 * proyector produce un VALOR y esto lo materializa.
 *
 * ── SE EJECUTA IGUAL QUE EN LA TERMINAL ─────────────────────────────────────────────────────────
 *
 * Mismo `SchemaCoercer` que usa el CLI para convertir texto en los tipos que el esquema declara, y
 * misma convención de veredicto: un `ok` booleano en la raíz del resultado ES el veredicto. Un
 * segundo camino de ejecución sería un segundo lugar donde arreglar el mismo defecto.
 *
 * ── LO QUE EXIGE FIRMA NO SE CORRE DESDE AQUÍ ───────────────────────────────────────────────────
 *
 * Una firma nombra ESTA llamada —la operación, sus argumentos, este host— y se produce con una llave
 * que vive fuera de esta pantalla. Un formulario que dijera «¿seguro?» consentiría en abstracto, que
 * es justo lo que la firma vino a reemplazar. Así que la pantalla se niega y muestra la línea exacta
 * a correr con `--sign`: no es una limitación disfrazada, es la única forma honesta de decir que el
 * consentimiento que hace falta no cabe aquí.
 */
final class OperationScreen
{
    private readonly RetainedTuiLoop $loop;

    /** @var array<string, string> lo tecleado por campo */
    private array $valores = [];

    /** @var list<string> */
    private array $salida = [];

    private string $estado = 'editando';

    private bool $ok = true;

    /** @var list<array{id: string, nombre: string, tipo: string, obligatorio: bool}> */
    private array $campos;

    public function __construct(
        private readonly Operation $operacion,
        private readonly ContainerInterface $container,
        int $width = 80,
        int $height = 24,
        bool $ansi = true,
        private readonly SchemaCoercer $coercer = new SchemaCoercer(),
        private readonly CliRenderer $renderer = new PlainTextCliRenderer(),
    ) {
        $this->campos = $this->camposDe($operacion);
        foreach ($this->campos as $campo) {
            $this->valores[$campo['nombre']] = '';
        }

        $ids = array_column($this->campos, 'id');
        $ids[] = 'correr';

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

    /** Los renderers que esta pantalla usa: texto y caja, que es todo lo que pinta. */
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
     * Lo tecleado hasta ahora, por campo.
     *
     * @return array<string, string>
     */
    public function values(): array
    {
        return $this->valores;
    }

    /** `editando`, `corrida` o `firma-requerida`. */
    public function state(): string
    {
        return $this->estado;
    }

    /** El veredicto de la última corrida — `false` sólo después de correr algo que falló. */
    public function ok(): bool
    {
        return $this->ok;
    }

    /**
     * Lo que la operación contestó, ya renderizado.
     *
     * @return list<string>
     */
    public function output(): array
    {
        return $this->salida;
    }

    /**
     * Teclear, borrar, y correr con Enter.
     *
     * El movimiento entre campos NO se reimplementa: Tab y shift+Tab los resuelve el tier, y un
     * segundo camino al mismo foco es un segundo camino que se puede desincronizar.
     */
    private function handleKey(string $key, RetainedTuiLoop $loop): bool
    {
        if ($key === 'enter') {
            $this->correr();

            return true;
        }

        $campo = $this->campoEnfocado($loop->focusedId());
        if ($campo === null) {
            return false;
        }

        if ($key === 'backspace') {
            $this->valores[$campo] = mb_substr($this->valores[$campo], 0, -1);

            return true;
        }

        // El CRUDO y no el nombre: `dispatchKey()` entrega el nombre canónico, que para un carácter
        // suelto viene en minúscula porque un atajo declarado como `l` tiene que casar con `L`. Eso
        // es correcto para un atajo y haría imposible teclear `MarketingPlugin` en un campo.
        $crudo = $loop->lastRawKey();
        if (mb_strlen($crudo) === 1 && preg_match('/^[[:print:]]$/u', $crudo) === 1) {
            $this->valores[$campo] .= $crudo;

            return true;
        }

        return false;
    }

    /**
     * Corre la operación con lo tecleado — o se niega si exige firma.
     *
     * La negativa lleva la línea exacta que sí funciona. Decir «no se puede» y callar dónde sí manda
     * a alguien a buscar en la ayuda lo que esta pantalla ya sabe.
     */
    private function correr(): void
    {
        if ($this->operacion->mutating && $this->operacion->requiresConfirmation) {
            $this->estado = 'firma-requerida';
            $this->ok = false;
            $this->salida = [
                'Esta operación exige una firma, y una firma nombra ESTA llamada.',
                'Córrela desde la terminal:',
                '',
                '  ' . $this->lineaCli(),
            ];

            return;
        }

        $crudo = [];
        foreach ($this->valores as $nombre => $valor) {
            if ($valor !== '') {
                $crudo[$nombre] = $valor;
            }
        }

        try {
            $entrada = $this->coercer->coerce($this->operacion->inputSchema ?? [], $crudo);
        } catch (SchemaCoercionException $e) {
            $this->estado = 'corrida';
            $this->ok = false;
            $this->salida = $this->renderer->presentError(implode(' · ', $e->errors));

            return;
        }

        try {
            $resultado = $this->invocar($entrada);
        } catch (\Throwable $e) {
            $this->estado = 'corrida';
            $this->ok = false;
            $this->salida = $this->renderer->presentError($e->getMessage());

            return;
        }

        $this->estado = 'corrida';
        $this->ok = $this->veredicto($resultado);
        $this->salida = $this->renderer->present($resultado, $this->ok);
    }

    /**
     * @param array<string, mixed> $entrada
     */
    private function invocar(array $entrada): mixed
    {
        $handler = $this->operacion->handler;
        if (\is_callable($handler)) {
            return $handler($entrada);
        }

        [$clase, $metodo] = $handler;
        $instancia = $this->container->get($clase);
        if (!\is_object($instancia)) {
            throw new \RuntimeException("«{$this->operacion->name}»: {$clase} no resolvió a un objeto.");
        }

        return $instancia->{$metodo}($entrada);
    }

    /**
     * El veredicto: un `ok` booleano en la raíz del resultado ES el veredicto.
     *
     * Es la misma convención que el CLI usa para el código de salida. Sin ella, una pantalla que
     * pinta un resultado de error en verde miente con más autoridad que un texto plano.
     */
    private function veredicto(mixed $resultado): bool
    {
        if (\is_array($resultado) && \array_key_exists('ok', $resultado) && \is_bool($resultado['ok'])) {
            return $resultado['ok'];
        }

        return true;
    }

    /** La línea de terminal equivalente, con lo que ya se tecleó y `--sign`. */
    private function lineaCli(): string
    {
        $linea = 'coa ' . str_replace(['_', '.'], ':', $this->operacion->name);
        foreach ($this->valores as $nombre => $valor) {
            if ($valor !== '') {
                $linea .= ' --' . str_replace('_', '-', $nombre) . '=' . escapeshellarg($valor);
            }
        }

        return $linea . ' --sign';
    }

    /**
     * Los campos que esta pantalla ofrece, tomados del MODELO que proyecta {@see TuiProjector}.
     *
     * Se leen de la proyección y no del esquema directo a propósito: si el proyector decide algún día
     * ocultar un campo o renombrarlo, esta pantalla lo respeta sin enterarse. Un formulario que
     * vuelve a leer el esquema es una segunda proyección compitiendo con la primera.
     *
     * @return list<array{id: string, nombre: string, tipo: string, obligatorio: bool}>
     */
    private function camposDe(Operation $operacion): array
    {
        $campos = [];
        foreach ((new TuiProjector())->project($operacion)->node->children as $hijo) {
            if ($hijo->type !== 'text-input') {
                continue;
            }
            $campos[] = [
                'id' => $hijo->id,
                'nombre' => \is_string($hijo->props['label'] ?? null) ? $hijo->props['label'] : $hijo->id,
                'tipo' => \is_string($hijo->props['type'] ?? null) ? $hijo->props['type'] : 'string',
                'obligatorio' => ($hijo->props['required'] ?? false) === true,
            ];
        }

        return $campos;
    }

    private function campoEnfocado(string $focusedId): ?string
    {
        foreach ($this->campos as $campo) {
            if ($campo['id'] === $focusedId) {
                return $campo['nombre'];
            }
        }

        return null;
    }

    /** El árbol de la pantalla: la operación, sus campos con lo tecleado, y el resultado. */
    private function tree(): TuiNode
    {
        $enfocado = $this->loop->focusedId();

        $hijos = [
            new TuiNode('titulo', 'text', props: ['text' => $this->operacion->name]),
            new TuiNode('descripcion', 'text', props: ['text' => $this->operacion->description]),
        ];

        foreach ($this->campos as $campo) {
            $marca = $campo['id'] === $enfocado ? '▸ ' : '  ';
            $obligatorio = $campo['obligatorio'] ? ' *' : '';
            $valor = $this->valores[$campo['nombre']];
            $cursor = $campo['id'] === $enfocado ? '▏' : '';
            $hijos[] = new TuiNode($campo['id'], 'text', props: [
                'text' => $marca . $campo['nombre'] . $obligatorio . ': ' . $valor . $cursor,
            ]);
        }

        if ($this->operacion->mutating) {
            $hijos[] = new TuiNode('muta', 'text', props: [
                'text' => $this->operacion->requiresConfirmation
                    ? '  ⚠ muta y exige firma — no se corre desde aquí'
                    : '  ⚠ esta operación cambia algo',
            ]);
        }

        if ($this->salida !== []) {
            $hijos[] = new TuiNode('separador', 'text', props: ['text' => str_repeat('─', 40)]);
            foreach ($this->salida as $i => $linea) {
                $hijos[] = new TuiNode('salida:' . $i, 'text', props: ['text' => $linea]);
            }
        }

        $hijos[] = new TuiNode('correr', 'text', props: [
            'text' => ($enfocado === 'correr' ? '▸ ' : '  ') . '[Enter] correr · [Tab] siguiente campo',
        ]);

        return new TuiNode('root', 'box', props: ['title' => 'coa · ' . $this->operacion->name], children: $hijos);
    }
}

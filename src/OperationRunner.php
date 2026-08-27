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

namespace Milpa\Console;

use Milpa\Command\InvocationContext;
use Milpa\Command\Operation;
use Milpa\Console\Events\OperationExecutedEvent;
use Milpa\Console\Events\OperationExecutingEvent;
use Milpa\Events\InterceptionSlot;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Psr\Container\ContainerInterface;

/**
 * El único lugar donde una operación se ejecuta, sea cual sea la superficie por la que llegó.
 *
 * ── POR QUÉ EXISTE ──────────────────────────────────────────────────────────────────────────────
 *
 * Porque había CUATRO. La terminal resolvía el handler y lo llamaba; HTTP hacía lo mismo con otras
 * cinco líneas; el TUI otra vez; y sólo el camino de MCP —que pasa por el registry de
 * `milpa/tool-runtime`— emitía eventos. Un gancho que auditara o bloqueara una operación que muta la
 * veía por MCP y no la veía en las otras tres.
 *
 * Y no era sólo la auditoría: cuatro caminos de ejecución son cuatro lugares donde arreglar el mismo
 * defecto. Dos veces esta semana un arreglo tuvo que aplicarse dos veces porque el segundo camino
 * existía.
 *
 * ── LOS GANCHOS ─────────────────────────────────────────────────────────────────────────────────
 *
 * `operation.executing` se emite ANTES, con una {@see InterceptionSlot}: un listener puede
 * **detenerla** —una política que niega, una cuota agotada— o **cortocircuitarla** devolviendo un
 * resultado sin que el handler corra, que es como se enchufa un caché. `operation.executed` se emite
 * SIEMPRE que hubo un desenlace, incluido el cortocircuito y el error, porque una auditoría con
 * huecos es peor que ninguna: enseña a confiar en una lista incompleta.
 *
 * El despachador es OPCIONAL. Un host que no cablee eventos ejecuta exactamente igual — lo que pierde
 * son los ganchos, no la capacidad.
 */
final readonly class OperationRunner
{
    /**
     * El contenedor se pide como PSR-11: lo único que hace con él es resolver la clase de un handler
     * declarado como `[Clase, 'metodo']`. Pedir el de la familia lo ataría a `milpa/core` por una
     * capacidad que el estándar ya cubre — y el de la familia lo satisface, porque extiende PSR-11.
     */
    public function __construct(
        private ContainerInterface $container,
        private ?MilpaEventDispatcherInterface $dispatcher = null,
    ) {
    }

    /**
     * Corre la operación con esta entrada y devuelve lo que contestó.
     *
     * @param array<string, mixed> $input ya coercionado por quien conoce la superficie: el CLI parte
     *                                    de argv, HTTP de query + cuerpo, el TUI de campos. Convertir
     *                                    texto a tipos es de la superficie; ejecutar es de aquí.
     *
     * @throws \Throwable lo que el handler lance, después de emitir `operation.executed`
     */
    public function run(
        Operation $operation,
        array $input,
        string $surface,
        ?InvocationContext $context = null,
    ): mixed {
        $slot = new InterceptionSlot();
        $this->dispatcher?->dispatch(
            'operation.executing',
            ['event' => new OperationExecutingEvent($operation, $input, $surface), 'slot' => $slot],
        );

        if ($slot->hasResult()) {
            // Cortocircuito: alguien contestó por el handler. Se audita igual —y marcado— porque un
            // resultado servido de caché que no aparece en la bitácora es un hueco que sólo se nota
            // cuando alguien pregunta por qué no aparece.
            $resultado = $slot->getResult();
            $this->emitExecuted($operation, $input, $surface, $resultado, shortCircuited: true, context: $context);

            return $resultado;
        }

        if ($slot->isStopped()) {
            // Detenida: NO es lo mismo que cortocircuitada. Nadie contestó por ella; alguien decidió
            // que no corriera, y quien llamó tiene que poder distinguirlo de un resultado.
            $this->emitExecuted($operation, $input, $surface, null, stopped: true, context: $context);

            throw new OperationStoppedException(
                "La operación «{$operation->name}» fue detenida por un listener de `operation.executing`.",
            );
        }

        try {
            $resultado = $this->invoke($operation, $input, $context);
        } catch (\Throwable $e) {
            // Se audita el fracaso ANTES de propagarlo: un error que no deja rastro es el que nadie
            // encuentra al día siguiente.
            $this->emitExecuted($operation, $input, $surface, null, error: $e, context: $context);

            throw $e;
        }

        $this->emitExecuted($operation, $input, $surface, $resultado, context: $context);

        return $resultado;
    }

    /**
     * El veredicto de un resultado: un `ok` booleano en su raíz ES el veredicto.
     *
     * Vive aquí porque las cuatro superficies lo necesitan y tres lo tenían escrito por separado: el
     * CLI para el código de salida, HTTP para el status, el TUI para pintar. Tres copias de una
     * convención son tres oportunidades de que una se quede atrás.
     *
     * No es una convención inventada: es la que la familia ya usaba —`{"ok": …}` en la salida `--json`
     * de los comandos del host, en la envoltura de {@see Rendering\JsonCliRenderer}— y lo que cambió
     * fue que se empezara a HONRAR. Que se ignorara costaba caro y en silencio: una operación de
     * diagnóstico que reportaba `ok: false` salía con código 0, así que un CI que la corría pasaba en
     * verde sobre un manifiesto inválido.
     *
     * Un resultado SIN `ok` es un acierto: la mayoría devuelve datos y no veredictos, y exigirles la
     * llave las obligaría a hablar de códigos de salida — o sea a saber que existe una terminal.
     */
    public static function verdict(mixed $resultado): bool
    {
        if (\is_array($resultado) && \array_key_exists('ok', $resultado) && \is_bool($resultado['ok'])) {
            return $resultado['ok'];
        }

        return true;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function invoke(Operation $operation, array $input, ?InvocationContext $context = null): mixed
    {
        // EL CONTEXTO VIAJA COMO SEGUNDO ARGUMENTO, y ésa es toda la mecánica: un handler que no lo
        // declara simplemente lo ignora —PHP no se queja de un argumento de más— y uno que sí lo
        // declara lo recibe por el mismo camino explícito que su entrada.
        //
        // La alternativa era el contenedor, y la descartó Rod con el argumento que la cierra: con
        // estado ambiental, OLVIDARSE de leer al actor no falla. Y lo que no falla al olvidarse
        // termina olvidado.
        $handler = $operation->handler;
        if (\is_callable($handler)) {
            return $handler($input, $context);
        }

        [$clase, $metodo] = $handler;
        $instancia = $this->container->get($clase);
        if (!\is_object($instancia)) {
            throw new \RuntimeException("operation '{$operation->name}': {$clase} did not resolve to an object.");
        }

        return $instancia->{$metodo}($input, $context);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function emitExecuted(
        Operation $operation,
        array $input,
        string $surface,
        mixed $resultado,
        bool $shortCircuited = false,
        bool $stopped = false,
        ?\Throwable $error = null,
        ?InvocationContext $context = null,
    ): void {
        $this->dispatcher?->dispatch('operation.executed', [
            'event' => new OperationExecutedEvent(
                $operation,
                $input,
                $surface,
                $resultado,
                shortCircuited: $shortCircuited,
                stopped: $stopped,
                error: $error,
                context: $context,
            ),
        ]);
    }
}

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

use Milpa\Command\Operation;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\ToolRuntime\Identity\FileNonceLedger;
use Milpa\ToolRuntime\Identity\GnupgSignatureVerifier;
use Milpa\ToolRuntime\Identity\OperationAuthorizer;

/**
 * Corre una operación en la terminal: deriva la entrada desde argv, pasa la compuerta de
 * consentimiento cuando la operación la exige, ejecuta y reporta.
 *
 * Salió de CliProjector porque escribía. Un projector produce un modelo y nada más (ADR-0035,
 * cláusula 1); todo lo que emite texto, resuelve servicios o ejecuta un handler es materialización,
 * y ese es el trabajo de esta clase.
 *
 * ── LO QUE TODAVÍA ESTÁ ENREDADO, DICHO ─────────────────────────────────────────────────────────
 *
 * La compuerta de firma vive aquí adentro, y no porque sea su lugar: es POLÍTICA, y la política no
 * pertenece ni a la proyección ni a la materialización. Vive en el eje `Intent → Policy → Signer`,
 * que ADR-0035 declara explícitamente fuera de su alcance. Sacarla es una decisión con su propio
 * ADR; mientras tanto queda nombrada aquí para que nadie la confunda con parte de ejecutar.
 */
final class CliRunner
{
    public function __construct(
        private readonly SchemaCoercer $coercer = new SchemaCoercer(),
        private readonly ?OperationSigner $signer = null,
        private readonly ?OperationAuthorizer $authorizer = null,
    ) {
    }

    /**
     * Turns `--sign` into a verified authorization for exactly this call, or refuses.
     *
     * The refusal paths matter as much as the success one, so each says what happened and what to
     * do: a card that declined is not a bad signature, and a bad signature is not an expired one.
     *
     * @param array<string, mixed>   $input
     * @param list<string>           $argv
     * @param callable(string): void $out
     *
     * @return int 0 when authorized, otherwise the exit code to return
     */
    private function authorizeBySignature(Operation $op, array $input, array $argv, callable $out): int
    {
        if (!\in_array('--sign', $argv, true)) {
            $out("This operation mutates and needs your authorization. Re-run with --sign.");
            $out('');
            $out('  --sign signs THIS call — the operation, these arguments, this host — with your');
            $out('  key. The authorization cannot be presented for a different target, which is');
            $out('  what a confirmation flag could never promise.');

            return 1;
        }

        $host = gethostname() ?: 'unknown-host';
        $now = time();

        $signed = ($this->signer ?? new GnupgOperationSigner())->sign($op->name, $input, $host, $now);
        if ($signed === null) {
            // Declining at the card lands here, and so does a missing key. Both mean the operation
            // does not run, and neither is an error in the operation.
            $out('✗ Nothing was signed, so nothing was authorized.');
            $out('  Either the signature was declined, or no usable key was found.');

            return 1;
        }

        [$payload, $signature] = $signed;

        $authorizer = $this->authorizer ?? new OperationAuthorizer(
            new GnupgSignatureVerifier(),
            new FileNonceLedger(\dirname(__DIR__, 2) . '/storage/authorizations'),
        );

        $verdict = $authorizer->authorize($op->name, $input, $host, $payload, $signature, $now);
        if (!$verdict->granted) {
            $out('✗ ' . (string) $verdict->reason);

            return 1;
        }

        // Printed, not just recorded: the operator sees which key answered before the effect
        // happens, so a wrong card is caught by the person rather than by an audit weeks later.
        $out('✓ authorized by ' . ($verdict->signer?->principal() ?? 'unknown'));

        return 0;
    }

    /**
     * Turns raw argv tokens into the typed input the handler declares.
     *
     * Separate from {@see self::run()} because what gets SIGNED has to be what RUNS: the
     * signature is over the derived arguments, so they have to exist before the gate, not after.
     *
     * @param list<string> $argv tokens after the command name
     *
     * @return array<string, mixed>
     *
     * @throws SchemaCoercionException
     */
    public function deriveInput(Operation $op, array $argv): array
    {
        return $this->coercer->coerce($op->inputSchema ?? [], $this->rawBag($argv));
    }

    /**
     * Runs the operation on this surface: derive input, pass the consent gate when the operation
     * demands it, execute, and report through `$out`.
     *
     * Returns a process exit code rather than a value — 0 ran, 1 refused or failed — which is the
     * shape a shell reads. That it executes at all is the deviation ADR-0035 names: a projector
     * should produce a model and let a renderer materialize it.
     *
     * @param list<string>           $argv
     * @param callable(string): void $out
     */
    public function run(Operation $op, array $argv, DIContainerInterface $container, callable $out): int
    {
        try {
            $input = $op->inputSchema !== null ? $this->deriveInput($op, $argv) : $this->rawBag($argv);
        } catch (SchemaCoercionException $e) {
            $out('✗ ' . $e->getMessage());

            return 1;
        }

        if ($op->mutating && $op->requiresConfirmation) {
            // The input has to be derived first now, which is the whole reason the order changed:
            // `--yes` could be answered before knowing what the arguments were, because it never
            // referred to them. A signature is over the arguments, so there is nothing to sign
            // until they exist.
            $authorized = $this->authorizeBySignature($op, $input, $argv, $out);
            if ($authorized !== 0) {
                return $authorized;
            }
        }

        $handler = $op->handler;
        if (\is_callable($handler)) {
            /** @var mixed $result */
            $result = $handler($input);
        } else {
            [$class, $method] = $handler;
            $instance = $container->get($class);
            if (!\is_object($instance)) {
                $out("✗ command '{$op->name}': {$class} did not resolve to an object.");

                return 1;
            }
            /** @var mixed $result */
            $result = $instance->{$method}($input);
        }

        if (\is_int($result)) {
            return $result;
        }
        if ($result !== null) {
            $out(\is_string($result) ? $result : (string) \json_encode($result));
        }

        return 0;
    }

    /**
     * @param list<string> $argv
     *
     * @return array<string, string>
     */
    private function rawBag(array $argv): array
    {
        $bag = [];
        foreach ($argv as $token) {
            if (!str_starts_with($token, '--')) {
                continue;
            }
            $body = substr($token, 2);
            [$key, $value] = str_contains($body, '=') ? explode('=', $body, 2) : [$body, '1'];
            $bag[$key] = $value;
        }

        return $bag;
    }
}

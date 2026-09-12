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
use Milpa\Command\InvocationContext;
use Milpa\ToolRuntime\Contracts\ToolContext;
use Milpa\ToolRuntime\Identity\VerifiedSigner;
use Milpa\ToolRuntime\PolicyGate;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Console\Rendering\CliRenderer;
use Milpa\Console\Rendering\PlainTextCliRenderer;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\ToolRuntime\Identity\FileNonceLedger;
use Milpa\ToolRuntime\Identity\GnupgSignatureVerifier;
use Milpa\ToolRuntime\Identity\GrantedAuthorization;
use Milpa\ToolRuntime\Identity\OperationAuthorization;
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
 *
 * Y por eso mismo la compuerta CONSERVA SU PROPIA VOZ: sus mensajes no pasan por el renderer. No es
 * un descuido — enrutarlos hoy congelaría su ubicación, porque un renderer que sabe pintar una
 * negativa de consentimiento es un renderer que da por sentado que el consentimiento vive aquí. Lo
 * que sí pasa por el renderer es lo que SÍ es de esta clase: el resultado de la operación y las
 * fallas de derivar su entrada. Queda dicho para que la diferencia se vea, no para que se olvide.
 */
final class CliRunner
{
    /**
     * El renderer es un default y no una dependencia obligatoria: un host que sólo quiere correr
     * operaciones no debería tener que elegir formato para empezar. Cambiarlo por
     * {@see \Milpa\Console\Rendering\JsonCliRenderer} no toca ni esta clase ni el projector, que es
     * exactamente lo que la segunda cláusula de ADR-0035 pide poder hacer.
     *
     * @param (\Closure(VerifiedSigner): ?ToolContext)|null $signerAuthority resolves the host's current
     *                                                                       recognition after verification
     */
    public function __construct(
        private readonly SchemaCoercer $coercer = new SchemaCoercer(),
        private readonly ?OperationSigner $signer = null,
        private readonly ?OperationAuthorizer $authorizer = null,
        private readonly CliRenderer $renderer = new PlainTextCliRenderer(),
        // Opcional, como en todo lo demás: un host que no cablea eventos corre igual y lo que pierde
        // son los ganchos, no la capacidad.
        private readonly ?MilpaEventDispatcherInterface $dispatcher = null,
        private readonly ?\Closure $signerAuthority = null,
        private readonly ?ToolContext $callerAuthority = null,
    ) {
    }

    /**
     * Turns `--sign` into a verified authorization for exactly this call, or refuses.
     *
     * The refusal paths matter as much as the success one, so each says what happened and what to
     * do: a card that declined is not a bad signature, and a bad signature is not an expired one.
     *
     * The receipt returns to the caller for scope judgment before it can reach a handler.
     * Verification alone cannot spend the authority of a recognized signer (greenhouse 0317).
     *
     * @param array<string, mixed>   $input
     * @param list<string>           $argv
     * @param callable(string): void $out
     *
     * @return GrantedAuthorization|int the verified receipt, or the refusal exit code
     */
    private function authorizeBySignature(Operation $op, array $input, array $argv, callable $out): GrantedAuthorization|int
    {
        if (!\in_array('--sign', $argv, true)) {
            // SAY THE FACT THE GATE READ, not one the operation may never have declared: a read with no
            // EffectProfile carries Unknown on every axis (GOV-05, greenhouse decisions/0227) and is demanded
            // for THAT — telling its author it «mutates» sends them to fix the wrong thing.
            $why = $op->mutating
                ? 'mutates'
                : ($op->effects === null
                    ? 'never declared its effects (unclassified counts as the maximum)'
                    : 'demands consent');
            $out("This operation {$why} and needs your authorization. Re-run with --sign.");
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

        // And carried, not just printed: the verdict used to end at that banner, which left a
        // handler wanting to persist the grant as an assertion (greenhouse decisions/0056) with
        // nothing but its own retelling. The RAW payload and signature travel with it because the
        // receipt doctrine (greenhouse evidence/0254) requires a consumer to RE-VERIFY, and a
        // paraphrase cannot be re-verified. The guards are for the types, not for doubt: a granted
        // verdict always carries its signer, and a payload the authorizer just accepted parses.
        $authorization = OperationAuthorization::fromCanonical($payload);
        if ($authorization !== null && $verdict->signer !== null) {
            return new GrantedAuthorization($authorization, $verdict->signer, $payload, $signature);
        }

        $out('The verified signature did not produce a usable authorization receipt.');

        return 1;
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
        // `--sign` controls this surface; it is not input for the operation. The original argv stays
        // intact for the signature gate, while the schema sees only the arguments it declares.
        $inputArgv = array_values(array_filter($argv, static fn (string $token): bool => $token !== '--sign'));

        return $this->coercer->coerce($op->inputSchema ?? [], $this->rawBag($inputArgv, $op->inputSchema ?? []));
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
            foreach ($this->renderer->presentError($e->getMessage()) as $linea) {
                $out($linea);
            }

            return 1;
        }

        // THE ARGUMENTS GO IN, because a descent is resolved for a CALL and not for an operation
        // in the abstract (greenhouse decisions/0029, measured inert in evidence/0152). The input is
        // already derived above, so this surface has them; the catalogue surfaces do not, and there
        // the ceiling stays up.
        $authority = $this->callerAuthority;
        $policy = new PolicyGate();
        $hostPolicy = $container->has(\Milpa\ToolRuntime\Contracts\CallPolicy::class)
            ? $container->get(\Milpa\ToolRuntime\Contracts\CallPolicy::class) : null;
        if ($hostPolicy instanceof \Milpa\ToolRuntime\Contracts\CallPolicy) {
            $policy->setCallPolicy($hostPolicy);
        }
        $tool = new \Milpa\ToolRuntime\ToolDefinition(
            McpProjector::toolName($op->name),
            $op->description,
            $op->inputSchema ?? [],
            $op->handler,
            scopes: $op->scopes,
            mutating: $op->mutating,
        );
        $admission = $policy->authorizeCall($authority ?? ToolContext::cli(), $tool, $input);
        if (!$admission->allowed) {
            foreach ($this->renderer->presentError((string) $admission->reason) as $line) {
                $out($line);
            }
            return 1;
        }
        $context = null;
        if (Consent::demanded($op, $input) || \in_array('--sign', $argv, true)) {
            // The input has to be derived first now, which is the whole reason the order changed:
            // `--yes` could be answered before knowing what the arguments were, because it never
            // referred to them. A signature is over the arguments, so there is nothing to sign
            // until they exist.
            $authorized = $this->authorizeBySignature($op, $input, $argv, $out);
            if (\is_int($authorized)) {
                return $authorized;
            }

            // A signature proves the current caller; the host's recognition bounds what it may do.
            // Never recover that caller from an old session's ownership assertion (greenhouse 0317).
            try {
                $authority = $this->signerAuthority?->__invoke($authorized->signer) ?? $authority;
            } catch (\Throwable $error) {
                foreach ($this->renderer->presentError($error->getMessage()) as $line) {
                    $out($line);
                }

                return 1;
            }
            if ($authority !== null) {
                $scope = $policy->authorizeCall($authority, $tool, $input);
                if (!$scope->allowed) {
                    foreach ($this->renderer->presentError((string) $scope->reason) as $line) {
                        $out($line);
                    }

                    return 1;
                }
            }
            $context = new InvocationContext(
                actor: 'key:' . $authorized->signer->fingerprint,
                verified: true,
                channel: 'cli',
                authorizationId: 'sha256:' . hash('sha256', $authorized->payload),
            );
            // Publish only after both checks passed: a refused caller must leave no usable grant.
            $out('✓ authorized by ' . $authorized->signer->principal());
            $container->registerService(GrantedAuthorization::class, $authorized);
        }

        // Por el runner y no a mano: es la única costura por la que pasan las cuatro superficies, y
        // por lo tanto el único lugar donde un gancho ve TODO. Cuando esto resolvía el handler por su
        // cuenta, un listener que auditaba una operación que muta la veía por MCP y no aquí.
        try {
            /** @var mixed $result */
            $result = (new OperationRunner($container, $this->dispatcher))->run($op, $input, 'cli', $context, $authority);
        } catch (OperationStoppedException $e) {
            foreach ($this->renderer->presentError($e->getMessage()) as $linea) {
                $out($linea);
            }

            return 1;
        } catch (\Throwable $e) {
            foreach ($this->renderer->presentError($e->getMessage()) as $linea) {
                $out($linea);
            }

            return 1;
        }

        // Un entero sigue siendo un CÓDIGO DE SALIDA y no un resultado que pintar: es la convención
        // con que un handler dice «ya reporté yo». Todo lo demás va al renderer, incluido `null` —
        // que devuelve cero líneas, no una línea vacía.
        if (\is_int($result)) {
            return $result;
        }

        $ok = OperationRunner::verdict($result);
        foreach ($this->renderer->present($result, $ok) as $linea) {
            $out($linea);
        }

        return $ok ? 0 : 1;
    }

    /**
     * Los tokens `--clave=valor` como bolsa cruda, consultando el esquema para saber qué se repite.
     *
     * Una bandera que aparece dos veces gana la última, SALVO que su propiedad esté declarada
     * `type: array` — entonces se acumulan en una lista. Sin esta consulta el protocolo de tokens no
     * podía transportar una lista en absoluto: la bolsa sólo producía cadenas y la rama `array` del
     * coercer exige un arreglo ya hecho, así que una entrada repetible quedaba imposible de
     * satisfacer desde una terminal. Se descubrió al convertir un comando con filtros repetibles.
     *
     * Que la forma dependa del ESQUEMA y no de cuántas veces se escribió la bandera es deliberado:
     * `--producer=a` con una sola aparición tiene que llegar como lista de uno, o el consumidor
     * tendría que aceptar las dos formas y ahí nace el `is_array()` defensivo de siempre.
     *
     * @param list<string>         $argv
     * @param array<string, mixed> $inputSchema
     *
     * @return array<string, string|list<string>>
     */
    private function rawBag(array $argv, array $inputSchema = []): array
    {
        /** @var array<string, array<string, mixed>> $propiedades */
        $propiedades = \is_array($inputSchema['properties'] ?? null) ? $inputSchema['properties'] : [];

        $bag = [];
        foreach ($argv as $token) {
            if (!str_starts_with($token, '--')) {
                continue;
            }
            $cuerpo = substr($token, 2);
            [$clave, $valor] = str_contains($cuerpo, '=') ? explode('=', $cuerpo, 2) : [$cuerpo, '1'];

            if (($propiedades[$clave]['type'] ?? null) === 'array') {
                /** @var list<string> $previo */
                $previo = \is_array($bag[$clave] ?? null) ? $bag[$clave] : [];
                $previo[] = $valor;
                $bag[$clave] = $previo;

                continue;
            }

            $bag[$clave] = $valor;
        }

        return $bag;
    }
}

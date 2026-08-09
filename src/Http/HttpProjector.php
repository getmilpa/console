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

namespace Milpa\Console\Http;

use Milpa\Command\HttpRouteModel;
use Milpa\Command\InvocationContext;
use Milpa\Command\Operation;
use Milpa\Command\OperationHttpPolicy;
use Milpa\Command\SurfaceProjector;
use Milpa\Console\ConfirmTokenStore;
use Milpa\Console\Consent;
use Milpa\Console\OperationRunner;
use Milpa\Console\OperationStoppedException;
use Milpa\Console\SchemaCoercer;
use Milpa\Console\SchemaCoercionException;
use Milpa\Http\HttpMethod;
use Milpa\Http\Routing\HandlerReference;
use Milpa\Http\Routing\Route;
use Milpa\Http\Routing\RouteResult;
use Milpa\Interfaces\Di\DIContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Proyecta operaciones a HTTP: una ruta por operación, y el controlador genérico al que apuntan.
 *
 * Es las dos mitades de ADR-0035 en una clase, y a propósito: {@see self::project()} produce un
 * VALOR —el modelo de ruta, sin montar nada ni consultar el contenedor— y {@see self::routes()} +
 * {@see self::handle()} materializan. Que convivan es lo que permite preguntar qué expone una
 * operación sin levantar un servidor.
 *
 * `handle()` busca la operación de la ruta que hizo match, deja que la política decida si quien
 * llama puede, aplica la compuerta de confirmación en dos pasos para lo que muta, junta ruta + query
 * + cuerpo en una sola bolsa de entrada, la valida contra el esquema declarado, llama al handler de
 * la operación y serializa lo que devuelva.
 *
 * ── LA IDENTIDAD ESTÁ DEL OTRO LADO DE UNA INTERFAZ ─────────────────────────────────────────────
 *
 * Esta clase no conoce `milpa/auth`. Quien sabe quién llama es {@see OperationHttpPolicy}, y por eso
 * el proyector pudo mudarse a `milpa/console` —junto a los otros tres— sin arrastrar identidad al
 * piso mínimo del framework. Vivía en `milpa/skeleton`, que era la única casa que tenía; cuando
 * skeleton se retiró como puerta de entrada, ésta era la capacidad que se iba con él.
 *
 * ── EL ENFORCEMENT VA EN `handle()`, NO EN EL MIDDLEWARE DE LA RUTA ─────────────────────────────
 *
 * Hay UNA instancia sirviendo TODAS las operaciones, y el `middleware[]` de una ruta se resuelve por
 * class-string a una única instancia compartida — que no puede cargar la lista de scopes de cada
 * operación. El handler genérico es el único lugar donde se sabe de qué operación se trata.
 *
 * ── UNA SOLA INSTANCIA POR APP ─────────────────────────────────────────────────────────────────
 *
 * Cada ruta sintetizada apunta a `HandlerReference(self::class, 'handle')`, que se resuelve por esa
 * misma clave al despachar. Si dos plugins registraran su propia instancia, la última ganaría la
 * ranura del contenedor y las rutas de la primera resolverían a una instancia que no conoce su
 * operación — y contestarían 404.
 */
final class HttpProjector implements SurfaceProjector
{
    /** @var array<string, Operation> keyed by operation name */
    private array $operations = [];

    /**
     * Las fábricas PSR-17 se inyectan y no se instancian.
     *
     * Un paquete de proyección no tiene por qué elegirle a nadie su implementación de PSR-7: quien
     * monta la app ya tiene una, y pedir la interfaz es lo que evita que `milpa/console` arrastre
     * una segunda a un árbol que ya la tiene.
     *
     * @param iterable<Operation> $operations
     */
    public function __construct(
        iterable $operations,
        private readonly DIContainerInterface $container,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
        private readonly SchemaCoercer $coercer = new SchemaCoercer(),
        private readonly ConfirmTokenStore $tokens = new ConfirmTokenStore(),
        // La política se inyecta y ya no se hereda: es el eje `Intent -> Policy -> Signer`, y tenerla
        // como colaborador es lo que permite verla, sustituirla y probarla sola.
        private readonly ?OperationHttpPolicy $policy = null,
        private readonly ?\Milpa\Interfaces\Event\MilpaEventDispatcherInterface $dispatcher = null,
    ) {
        foreach ($operations as $op) {
            if ($op->supportsSurface('http')) {
                $this->operations[$op->name] = $op;
            }
        }
    }

    /** La superficie que proyecta — con la que el registro de projectors lo encuentra. */
    public function surface(): string
    {
        return 'http';
    }

    /**
     * Si esta operación se ofrece por HTTP.
     *
     * Un proyector que reclamara todas expondría por la red las que su autor declaró sólo para la
     * terminal, que es la clase de fuga que nadie revisa porque nadie la escribió.
     */
    public function supports(Operation $op): bool
    {
        return $op->supportsSurface('http');
    }

    /**
     * La ruta que HTTP expone para esta operación, como valor: no monta nada, no atiende nada, no
     * consulta al contenedor.
     */
    public function project(Operation $op): HttpRouteModel
    {
        return new HttpRouteModel(
            method: $op->mutating ? HttpMethod::POST->value : HttpMethod::GET->value,
            path: $this->pathFor($op),
            name: $op->name,
            scopes: $op->scopes,
            permission: $op->permission,
        );
    }

    /**
     * Una ruta por operación, todas apuntando al mismo handler genérico.
     *
     * @return list<Route>
     */
    public function routes(): array
    {
        $routes = [];
        foreach ($this->operations as $op) {
            $routes[] = new Route(
                path: $this->pathFor($op),
                methods: $op->mutating ? HttpMethod::POST : HttpMethod::GET,
                name: $op->name,
                handler: new HandlerReference(self::class, 'handle'),
            );
        }

        return $routes;
    }

    /** Atiende una petición ya ruteada: autoriza, confirma, valida, ejecuta y serializa. */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $result = $request->getAttribute(RouteResult::ATTRIBUTE);

        $route = null;
        $parameters = [];
        if ($result instanceof RouteResult) {
            $route = $result->route;
            $parameters = $result->parameters;
        }

        $op = $route?->name !== null ? ($this->operations[$route->name] ?? null) : null;

        if ($op === null) {
            return $this->json(404, ['error' => 'operation not found']);
        }

        // Una operación SIN scopes ni permiso no toca nada de esto: es idéntica a como corría antes
        // de que existiera política, y por eso un host sin identidad puede exponer operaciones.
        if ($op->scopes !== [] || $op->permission !== null) {
            if ($this->policy === null) {
                throw $op->permission !== null
                    ? UnguardedOperationException::permissioned($op->name, $op->permission)
                    : UnguardedOperationException::scoped($op->name, $op->scopes);
            }

            $denied = $this->policy->enforce($op, $request);
            if ($denied !== null) {
                return $denied;
            }
        }

        if (Consent::demanded($op)) {
            $token = $request->getHeaderLine('Confirm-Token');
            if ($token === '') {
                return $this->json(428, [
                    'requires_confirmation' => true,
                    'confirm_token' => $this->tokens->issue($op->name),
                ]);
            }
            if (!$this->tokens->consume($token, $op->name)) {
                return $this->json(428, [
                    'requires_confirmation' => true,
                    'error' => 'invalid or expired confirmation token',
                    'confirm_token' => $this->tokens->issue($op->name),
                ]);
            }
        }

        $raw = $parameters;
        foreach ($request->getQueryParams() as $key => $value) {
            $raw[$key] = $value;
        }
        /** @var mixed $body */
        $body = json_decode((string) $request->getBody(), true);
        if (\is_array($body)) {
            foreach ($body as $key => $value) {
                $raw[$key] = $value;
            }
        }

        try {
            $input = $this->coercer->coerce($op->inputSchema ?? [], $raw);
        } catch (SchemaCoercionException $e) {
            return $this->json(422, ['errors' => $e->errors]);
        }

        // Por el runner, como las otras tres superficies: es donde viven los ganchos y el veredicto.
        try {
            /** @var mixed $data */
            $data = (new OperationRunner($this->container, $this->dispatcher))
                ->run($op, $input, 'http', $this->contextoDe($request, $op));
        } catch (OperationStoppedException $e) {
            // Detenida por un listener: 409, porque no es culpa de quien llamó ni un error del
            // servidor — es un estado que impide correrla ahora.
            return $this->json(409, ['error' => $e->getMessage(), 'code' => 'MILPA_OPERATION_STOPPED']);
        } catch (\Throwable $e) {
            return $this->json(500, ['error' => $e->getMessage()]);
        }

        return $this->json($op->mutating ? 201 : 200, $data);
    }

    /**
     * Quién está corriendo esto, traducido de lo que la autenticación ya dejó en la petición.
     *
     * ── POR QUÉ AQUÍ Y NO EN EL CONTENEDOR ──────────────────────────────────────────────────────
     *
     * Porque la identidad es de la PETICIÓN y el contenedor es de la aplicación. Guardarla ahí crea
     * estado ambiental: una operación puede olvidarse de leer al actor y seguir funcionando, y el
     * contenedor puede conservar el de la petición anterior. Aquí se traduce y viaja.
     *
     * ── LO QUE SE TRADUCE Y LO QUE SE DEJA ──────────────────────────────────────────────────────
     *
     * Del `AuthContext` sale **quién** y **si se verificó**. Los scopes NO cruzan: ya sirvieron para
     * que la política autorizara, y una operación que puede leerlos es una que puede volver a decidir
     * con ellos. La política autoriza; la operación atribuye.
     *
     * Sin actor autenticado se devuelve un contexto **sin actor** —no uno con el proceso del servidor
     * en su lugar—. Poner `www-data` donde iba una persona convierte una cadena de custodia real en
     * una falsa, y una operación que exija atribución tiene que poder negarse.
     */
    private function contextoDe(ServerRequestInterface $request, Operation $op): InvocationContext
    {
        // SE LEE EL ATRIBUTO, NO LA CLASE. Este paquete no depende de `milpa/auth` y no debe:
        // acoplarlo obligaría a que cualquiera que quiera servir operaciones por HTTP se traiga el
        // sistema de identidad. El atributo `milpa.auth` es la costura, y quien lo pone declara su
        // forma — un actor con `id`. Sin esa forma, no hay actor.
        $auth = $request->getAttribute('milpa.auth');
        $ejecutor = (getenv('USER') ?: getenv('USERNAME') ?: 'desconocido') . '@' . (gethostname() ?: 'desconocido');
        $actor = \is_object($auth) && property_exists($auth, 'actor') ? $auth->actor : null;
        $actorId = \is_object($actor) && property_exists($actor, 'id') && \is_string($actor->id) ? $actor->id : null;

        if ($actorId !== null && $actorId !== '') {
            return InvocationContext::web(
                actor: 'actor:' . $actorId,
                // LA DECISIÓN QUE AUTORIZÓ, nombrada. Sin esto «autorizado» es una palabra: la
                // operación se nombra a sí misma como la decisión bajo la que corrió.
                authorizationId: $op->name,
                executor: $ejecutor,
                correlationId: $request->getHeaderLine('X-Request-Id') ?: null,
            );
        }

        return new InvocationContext(
            actor: null,
            verified: false,
            channel: 'web',
            executor: $ejecutor,
            correlationId: $request->getHeaderLine('X-Request-Id') ?: null,
        );
    }

    /**
     * La ruta de una operación: la que declaró, o una derivada de su nombre (`_` → `-`, `:` → `/`).
     */
    private function pathFor(Operation $op): string
    {
        if ($op->path !== null) {
            return $op->path;
        }
        $segments = array_map(
            static fn (string $s): string => str_replace('_', '-', $s),
            explode(':', $op->name),
        );

        $this->assertValidDerivedSegments($op->name, $segments);

        return '/' . implode('/', $segments);
    }

    /**
     * Protege una ruta DERIVADA —nunca una declarada— de degenerar en algo que la gramática del
     * router no puede expresar: un segmento vacío (`//` o una barra final) o con `{`/`}`, que
     * formaría a medias un parámetro que nadie quiso. Falla al sintetizar la ruta, en el arranque, en
     * vez de registrar en silencio una ruta rota.
     *
     * @param list<string> $segments
     */
    private function assertValidDerivedSegments(string $name, array $segments): void
    {
        foreach ($segments as $segment) {
            if ($segment === '' || str_contains($segment, '{') || str_contains($segment, '}')) {
                throw new \InvalidArgumentException(
                    "Operation '{$name}' derives an invalid HTTP path segment from its name "
                    . "(derived path: '/" . implode('/', $segments) . "'). "
                    . 'Set an explicit path: on the Operation instead of relying on derivation.',
                );
            }
        }
    }

    private function json(int $status, mixed $data): ResponseInterface
    {
        return $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream((string) json_encode($data)));
    }
}

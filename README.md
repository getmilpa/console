<p align="center">
  <a href="https://github.com/getmilpa">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-dark.svg">
      <img src="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-light.svg" alt="Milpa" width="300">
    </picture>
  </a>
</p>

# Milpa Console

> The **projection layer** of Milpa. [`milpa/command`](https://github.com/getmilpa/command) declares
> the atom — one surface-agnostic `Operation`; this package turns that atom into the shape each
> surface actually speaks. Today: **CLI** (flags, argument coercion, the signature gate) and **MCP**
> (tools an agent can call). One declaration, N surfaces.

[![CI](https://github.com/getmilpa/console/actions/workflows/ci.yml/badge.svg)](https://github.com/getmilpa/console/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/milpa/console)](https://packagist.org/packages/milpa/console)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue)](LICENSE)

## Install

```bash
composer require milpa/console
```

## Quick example

A plugin declares an operation once, through `milpa/command`'s `CommandProvider`. It does not say
anything about flags, JSON-schema or terminals:

```php
use Milpa\Command\Operation;

new Operation(
    name: 'create_post',
    description: 'Create a draft post',
    handler: [PostService::class, 'create'],
    inputSchema: ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]],
    mutating: true,
    requiresConfirmation: true,
);
```

The projectors give it a surface:

```php
use Milpa\Console\CliProjector;
use Milpa\Console\McpProjector;

// CLI: derives `--title=…` from the schema, coerces the string argv into typed input, and
// enforces the signature gate before a mutating operation runs.
(new CliProjector($authorizer))->run($operation, $argv, $container, $write);

// MCP: the same operation becomes a tool an agent can list and call.
(new McpProjector())->project($operations, $registry, $container);
```

## Consent names the call

On a terminal, `requiresConfirmation: true` is not a `--yes` flag. A flag consents in the abstract —
the same yes covers removing any plugin on any host — so `CliProjector` asks for a **signature that
names this call**: the operation, its arguments, the host and a nonce. `SchemaCoercer` turns argv
strings into the types the schema declares before any of that, so what gets signed is what runs.

The pieces are seams, not concretions: `OperationSigner` is the port,
[`GnupgOperationSigner`](src/GnupgOperationSigner.php) an adapter, and verification and nonce
spending live behind `milpa/tool-runtime`'s `OperationAuthorizer`.

## Testing your own surfaces

`Milpa\Console\Testing\SignsOperations` ships in `src/` on purpose: Composer does not autoload a
dependency's `autoload-dev`, so a test helper that lives in `tests/` is unreachable for whoever
consumes the package. The trait hands you an always-signing signer and an accepting authorizer, so a
test that just needs to get past the gate can do so without a real key.

## Where this is going

[ADR-0035](https://github.com/getmilpa/governance) — *a projection is a value, not an effect* —
governs this package. Today `CliProjector::run()` executes and `McpProjector::project()` registers;
neither returns a surface model, and that is the thing being retrofitted: a projector will produce a
model and a renderer will materialize it, so a surface can change its renderer without touching its
projector.

## HTTP, without dragging identity in

`Milpa\Console\Http\HttpProjector` is the fourth surface: one route per operation, plus the generic
controller those routes point at. It arrived in 0.4.0 — until then it lived in `milpa/skeleton`,
because moving it as-is would have dragged `milpa/auth` into a floor meant to run without it.

What made the move possible is that identity now sits behind an interface. The projector knows
nothing about who is calling; `OperationHttpPolicy` does, and
[`milpa/admin`](https://packagist.org/packages/milpa/admin) publishes the implementation that uses
`milpa/auth`. Write your own and the projector will use it.

```php
use Milpa\Console\Http\HttpProjector;

// $psr17 is any PSR-17 factory pair you already have (Nyholm, Guzzle, Laminas…).
$projector = new HttpProjector($operations, $container, $psr17, $psr17, policy: $yourPolicy);

$routes = $projector->routes();     // hand these to your router
$model  = $projector->project($op); // or just ask what an operation would expose
```

Unlike the other optional collaborators in this family, **not knowing is not permission here**: an
operation that declares scopes with no policy wired throws `UnguardedOperationException` (a 500)
rather than running unguarded. It stays a 500 and never a 401/403 — the caller did nothing wrong; the
host declared something protected and left it without a guard. An operation that declares neither
scopes nor a permission never touches any of this.

## Requirements

- PHP >= 8.3
- [`milpa/command`](https://github.com/getmilpa/command), [`milpa/core`](https://github.com/getmilpa/core),
  [`milpa/tool-runtime`](https://github.com/getmilpa/tool-runtime), [`milpa/http`](https://github.com/getmilpa/http)
- `psr/http-message` and `psr/http-factory` — interfaces only. The HTTP projector asks for the PSR-17
  factories instead of picking a PSR-7 implementation for you.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) and [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md).

## License

Apache-2.0 © Rodrigo Vicente - TeamX Agency. See [LICENSE](LICENSE) and [NOTICE](NOTICE).

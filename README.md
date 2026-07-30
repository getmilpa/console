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

The HTTP projector is deliberately **not** here yet. It conflates projection, policy enforcement and
materialization in one class, and moving it as-is would drag `milpa/auth` into a framework floor that
is meant to run without it.

## Requirements

- PHP >= 8.3
- [`milpa/command`](https://github.com/getmilpa/command), [`milpa/core`](https://github.com/getmilpa/core),
  [`milpa/tool-runtime`](https://github.com/getmilpa/tool-runtime)

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) and [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md).

## License

Apache-2.0 © Rodrigo Vicente - TeamX Agency. See [LICENSE](LICENSE) and [NOTICE](NOTICE).

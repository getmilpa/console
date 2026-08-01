# Changelog

## [0.7.0](https://github.com/getmilpa/console/compare/v0.6.1...v0.7.0) (2026-08-01)


### Features

* el runner lleva el contexto de invocacion hasta el handler ([fc24060](https://github.com/getmilpa/console/commit/fc2406018255136441a853ac9d2cc7713017a652))

## [0.6.1](https://github.com/getmilpa/console/compare/v0.6.0...v0.6.1) (2026-08-01)


### Bug Fixes

* **deps:** el pin de milpa/core deja de ser una jaula de un minor ([ca41421](https://github.com/getmilpa/console/commit/ca41421a906e712f0d576781ee53f338d18ef163))

## [0.6.0](https://github.com/getmilpa/console/compare/v0.5.0...v0.6.0) (2026-07-31)


### Features

* OperationRunner — one place where an operation runs, and one place a hook sees it ([7402a51](https://github.com/getmilpa/console/commit/7402a51181c2945cf6a5a3afaa0df074b9e38f53))

## [0.5.0](https://github.com/getmilpa/console/compare/v0.4.2...v0.5.0) (2026-07-31)


### Features

* the TUI surface stops being a screen you read ([af3625d](https://github.com/getmilpa/console/commit/af3625d27978db46aa1c2bc4cbcb7f28c51a4d98))

## [0.4.2](https://github.com/getmilpa/console/compare/v0.4.1...v0.4.2) (2026-07-31)


### Bug Fixes

* the HTTP policy contract now lives in milpa/command ([47b7bf1](https://github.com/getmilpa/console/commit/47b7bf168c3db7577f11dda14d52eec042699cc3))

## [0.4.1](https://github.com/getmilpa/console/compare/v0.4.0...v0.4.1) (2026-07-31)


### Bug Fixes

* require milpa/plugin ^0.6 ([56dc871](https://github.com/getmilpa/console/commit/56dc871283d00f5487531b929f52a241d6450730))

## [0.4.0](https://github.com/getmilpa/console/compare/v0.3.1...v0.4.0) (2026-07-31)


### Features

* the HTTP projector lands here, with identity behind an interface ([a03502e](https://github.com/getmilpa/console/commit/a03502ee16248858fa206d694981e80ebe0c6fe1))

## [0.3.1](https://github.com/getmilpa/console/compare/v0.3.0...v0.3.1) (2026-07-31)


### Bug Fixes

* require milpa/plugin ^0.5 ([1872585](https://github.com/getmilpa/console/commit/18725850440e8d67ca39dfee303c79458ec83d41))

## [0.3.0](https://github.com/getmilpa/console/compare/v0.2.0...v0.3.0) (2026-07-31)


### ⚠ BREAKING CHANGES

* a projected tool's name is now normalised — anything outside `[a-zA-Z0-9_-]` becomes `_`, and names longer than 64 characters are truncated. An operation named `plugins.list` registers as the tool `plugins_list`.

### Features

* normalise MCP tool names to what the spec accepts ([e172915](https://github.com/getmilpa/console/commit/e172915d5f4b4476ff5a8e8d4ad0ce5bcc93defa))

## [0.2.0](https://github.com/getmilpa/console/compare/v0.1.0...v0.2.0) (2026-07-30)


### ⚠ BREAKING CHANGES

* `CliRunner`'s default output is now text for a person, not JSON. A host that wants JSON declares it: `new CliRunner(renderer: new JsonCliRenderer())`. That JSON was never a decision — `json_encode` was the only path a non-scalar result had.

### Features

* a renderer materialises what a projector produced ([6a9b1ed](https://github.com/getmilpa/console/commit/6a9b1ed8446edd1b44125b47fb267c0d679c8350))

## 0.1.0 (2026-07-30)


### Features

* milpa/console — the projection layer ([ddf6328](https://github.com/getmilpa/console/commit/ddf63281d88102a0d1b2ffdcd837197d276715c6))


### Miscellaneous Chores

* cut the first line at 0.1.0, not 1.0.0 ([b769e8f](https://github.com/getmilpa/console/commit/b769e8f8533bb784c501c72529f557a07c86200a))

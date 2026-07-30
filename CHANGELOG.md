# Changelog

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

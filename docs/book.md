# LaraFly by Example

Learn LaraFly by building **Lumen**, the wallet-and-ledger service included in the framework.
Both editions cover the same application: a quick start, sixteen chapters, a Laravel cheat-sheet,
and a glossary. Every PHP listing is checked against the repository or linted by PHP.

## Download the book

| Edition | PDF | EPUB |
|---|---|---|
| English | [Download PDF](https://fireflyframework.github.io/fireflyframework-php/downloads/larafly-by-example.pdf) | [Download EPUB](https://fireflyframework.github.io/fireflyframework-php/downloads/larafly-by-example.epub) |
| Español | [Descargar PDF](https://fireflyframework.github.io/fireflyframework-php/downloads/larafly-by-example-es.pdf) | [Descargar EPUB](https://fireflyframework.github.io/fireflyframework-php/downloads/larafly-by-example-es.epub) |

These downloads track the latest `main` commit that passes all CI checks. The
[build record](https://fireflyframework.github.io/fireflyframework-php/downloads/build-info.json)
identifies the source commit, and
[SHA256SUMS](https://fireflyframework.github.io/fireflyframework-php/downloads/SHA256SUMS)
lets you verify the downloaded files. Versioned copies are attached to
[GitHub releases](https://github.com/fireflyframework/fireflyframework-php/releases).

## Start with the current installation

The book uses the single Composer package, `fireflyframework/larafly`, and its bundled installer:

```bash
composer global require fireflyframework/larafly
firefly new my-app
```

All 30 components are included in that package. An existing application starts with
`composer require fireflyframework/larafly`; compatible legacy `firefly/*` requirements are then
satisfied by the framework's `replace` declarations. See [Installation](installation.md) for details.

## Read and contribute

Browse the [English manuscript](https://github.com/fireflyframework/fireflyframework-php/tree/main/book/src)
or [Spanish manuscript](https://github.com/fireflyframework/fireflyframework-php/tree/main/book/src-es),
run the [Lumen sample](https://github.com/fireflyframework/fireflyframework-php/tree/main/samples/lumen),
or follow the [book build instructions](https://github.com/fireflyframework/fireflyframework-php/blob/main/book/README.md).

The shorter [English tutorial](tutorial.md) and [tutorial en español](tutorial.es.md) are also available online.

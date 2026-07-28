## Placeholder Chapter — Build-System Proof

This chapter is scaffolding, not content: Task B1 built the `book/` EPUB+PDF
pipeline (WeasyPrint, EPUB assembler, Markdown extensions, theme, PHP
listing verifier) and this page exists to prove every moving part renders
correctly end-to-end. Tasks B2 through B5 replace it with the real thirteen
chapters — Dependency Injection, Configuration, CQRS, Security, and the rest.

### A code listing

Real chapters fence PHP listings with a plain ```` ```php ```` block, which is
both syntax-highlighted here and linted by `book/build/verify_code.py` via
`php -l`. This excerpt is taken from the `samples/lumen` value object:

```php
<?php

declare(strict_types=1);

namespace Lumen\Domain;

final readonly class Money
{
    public function __construct(public int $minorUnits, public Currency $currency) {}

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }
}
```

### A figure

Diagrams use the `::: figure` directive and are inlined as SVG so they render
crisply in both the EPUB and the print PDF:

::: figure art/figures/di-autoconfig.svg | Figure 0.1 — Dependency injection and auto-configuration (starter figure, copied from docs/assets/diagrams/)

### Callouts

Four callout styles are available. Three carry over from PyFly's book system;
the fourth is LaraFly's own Laravel-parity callout:

!!! note "Note"
    Notes provide supplementary context or clarify a subtlety in the main text.

!!! tip "Tip"
    Tips share a shortcut, idiom, or best practice.

!!! warning "Warning"
    Warnings flag a common mistake or a sharp edge.

!!! laravel "Laravel parity"
    Laravel-parity callouts map a LaraFly concept directly to its native
    Laravel equivalent — e.g. `#[RestController]` next to a plain Laravel
    controller — for developers coming from stock Laravel.

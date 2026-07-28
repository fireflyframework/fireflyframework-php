## Conventions

This page explains the typographic and structural conventions used throughout the book.

### Code Listings

Real code is set in a plain fenced block tagged `php`. Every one of these blocks is both syntax-highlighted here and linted with the real PHP CLI (`php -l`) by the book's own build tooling — nothing you read is unparseable, invented, or out of date. For example, this excerpt is the `Money` value object from `samples/lumen`:

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

Inline code references within prose use `monospace` font, as in "the `#[Service]` attribute registers the class with LaraFly's container."

### Callouts

Four callout styles appear throughout the book:

!!! note "Note"
    Notes provide supplementary context or clarify a subtlety in the main text — worth reading, but not blocking.

!!! tip "Tip"
    Tips share a shortcut, idiom, or best practice that will save you time in real projects.

!!! warning "Warning"
    Warnings flag a common mistake or a sharp edge that can cause hard-to-debug problems if ignored.

!!! laravel "Laravel parity"
    Laravel-parity callouts map a LaraFly concept directly to its native Laravel equivalent — ideal if you are coming from stock Laravel rather than from Spring Boot or another convention-over-configuration framework.

### Figures

Diagrams are captioned below the image and embedded as inline SVG, so they render crisply at any zoom level in both the screen and print editions. You will meet the first one in Chapter 2.

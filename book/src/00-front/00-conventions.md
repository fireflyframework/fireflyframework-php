## Conventions

This page explains the typographic and structural conventions used throughout the book.

### Code Listings

Real code is set in a plain fenced block tagged `php`, and **every one of those listings says where it comes from**. Directly above each fence sits an HTML comment — invisible in the rendered page, but the reason you can trust what follows it — and there are exactly two kinds:

`<!-- source: <path> -->` marks **framework code**: the block is a *verbatim* excerpt of that file in the LaraFly repository — the same lines, in the same order, at the same relative indentation. `tests/DocsCodeIsRealTest.php` compares every such block against the file it names on every run, so a listing cannot drift when the code moves; and the file it quotes is already held to the repository's own gates — PHPStan at level max, Pint, and the package's Pest suite. Nothing in one of these blocks is paraphrased, tidied for the page, or invented.

`<!-- illustrative: <why> -->` marks **your** code: the class a reader writes in their own application, which by definition cannot exist in this repository, so there is no file to compare it against. Those blocks are the ones linted with the real PHP CLI (`php -l`), and every `use Firefly\…` they import and every `#[Attribute]` they use is checked against the classes the framework really declares.

Both kinds are additionally checked for what they *assert*: every `firefly.*` configuration key, every `php artisan firefly:*` command and every `composer <script>` a listing names has to be one that really exists.

### Elisions (`// …`)

A `source:` excerpt may **cut** whole lines it does not need — an import list, a long docblock, the middle of a method. Every cut is marked with a line that is exactly `// …` (or `# …` in a file, such as YAML, whose comments start with a hash), and the comparison resumes after what it has already matched, so what remains is still, in order, what the file says. Two things a cut may never do, both refused by the guard: swallow the declaration it belongs to — leaving a `{` with nothing above it saying *what* is being declared — or hollow a method out to an empty body, which would print a method that appears to do nothing.

For example, this is the `Money` value object from `samples/lumen`, whole down to `zero()` and then cut:

<!-- source: samples/lumen/src/Domain/Money.php -->
```php
<?php

declare(strict_types=1);

namespace Lumen\Domain;

use Firefly\Domain\ValueObject;
use Firefly\Domain\ValueObjectEquality;
use Firefly\Kernel\Exception\Business\ConflictException;

final readonly class Money implements ValueObject
{
    use ValueObjectEquality;

    public function __construct(public int $minorUnits, public Currency $currency) {}

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }
// …
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

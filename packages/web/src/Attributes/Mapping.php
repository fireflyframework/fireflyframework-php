<?php

declare(strict_types=1);

namespace Firefly\Web\Attributes;

/**
 * Marker interface for method-level HTTP mappings. RouteScanner discovers all verb mappings by querying each
 * method for attributes that are instances of this marker (the IS_INSTANCEOF attribute filter), so adding a
 * new verb attribute needs no scanner change. Alongside the HTTP verb from method(), implementers expose the
 * routing metadata the scanner reads polymorphically — path(), status() and name() — mirrored by their
 * public $path/$status/$name so the concrete attributes stay ergonomic to construct and read directly.
 */
interface Mapping
{
    public function method(): string;

    public function path(): string;

    public function status(): int;

    public function name(): ?string;
}

<?php

declare(strict_types=1);

namespace Firefly\Web\Attributes;

/**
 * Marker interface for method-level HTTP mappings. RouteScanner discovers all verb mappings via
 * getAttributes(Mapping::class, ReflectionAttribute::IS_INSTANCEOF), so adding a new verb attribute needs
 * no scanner change. Implementers also expose public $path, $status, $name.
 */
interface Mapping
{
    public function method(): string;
}

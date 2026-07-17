<?php

declare(strict_types=1);

namespace Firefly\Web\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final class PatchMapping implements Mapping
{
    public function __construct(
        public readonly string $path = '',
        public readonly int $status = 200,
        public readonly ?string $name = null,
    ) {}

    public function method(): string
    {
        return 'PATCH';
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Web\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final class DeleteMapping implements Mapping
{
    public function __construct(
        public readonly string $path = '',
        public readonly int $status = 200,
        public readonly ?string $name = null,
    ) {}

    public function method(): string
    {
        return 'DELETE';
    }

    public function path(): string
    {
        return $this->path;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function name(): ?string
    {
        return $this->name;
    }
}

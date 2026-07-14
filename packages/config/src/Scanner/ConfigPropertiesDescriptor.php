<?php

declare(strict_types=1);

namespace Firefly\Config\Scanner;

final readonly class ConfigPropertiesDescriptor
{
    public function __construct(
        public string $class,
        public string $prefix,
    ) {}

    /**
     * @return array{class: string, prefix: string}
     */
    public function toArray(): array
    {
        return ['class' => $this->class, 'prefix' => $this->prefix];
    }

    /**
     * @param  array{class: string, prefix: string}  $d
     */
    public static function fromArray(array $d): self
    {
        return new self($d['class'], $d['prefix']);
    }
}

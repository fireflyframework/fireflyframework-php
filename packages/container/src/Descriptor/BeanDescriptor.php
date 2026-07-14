<?php

declare(strict_types=1);

namespace Firefly\Container\Descriptor;

use Firefly\Container\Scope;

final readonly class BeanDescriptor
{
    public function __construct(
        public string $method,
        public string $returns,
        public ?string $name,
        public Scope $scope,
        public bool $primary,
        public int $order,
    ) {}

    /**
     * @return array{method: string, returns: string, name: string|null, scope: string, primary: bool, order: int}
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'returns' => $this->returns,
            'name' => $this->name,
            'scope' => $this->scope->name,
            'primary' => $this->primary,
            'order' => $this->order,
        ];
    }

    /**
     * @param  array{method: string, returns: string, name: string|null, scope: string, primary: bool, order: int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['method'],
            $data['returns'],
            $data['name'],
            self::scopeFromName($data['scope']),
            $data['primary'],
            $data['order'],
        );
    }

    private static function scopeFromName(string $name): Scope
    {
        return match ($name) {
            'Singleton' => Scope::Singleton,
            'Transient' => Scope::Transient,
            'Scoped' => Scope::Scoped,
            default => throw new \ValueError("Unknown Scope case: {$name}"),
        };
    }
}

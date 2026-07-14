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
        /**
         * Captured from #[Primary] on the #[Bean] method, but NOT yet
         * consulted during registration in this milestone (registerBeans()
         * ignores it). Reserved for future bean-collision disambiguation,
         * analogous to how ContainerRegistrar::wireInterfaces() uses
         * ComponentDescriptor::$primary to pick a default interface impl.
         */
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
            Scope::fromName($data['scope']),
            $data['primary'],
            $data['order'],
        );
    }
}

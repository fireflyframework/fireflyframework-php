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
        /**
         * Captured from #[Lazy] on the #[Bean] factory method. Consulted by
         * EagerSingletonsPass, which skips eagerly resolving this bean at boot when true —
         * read straight off this field, never via reflection (see ComponentScanner, which
         * captures it, and Firefly\Container\Attributes\Lazy's docblock).
         */
        public bool $lazy = false,
    ) {}

    /**
     * @return array{method: string, returns: string, name: string|null, scope: string, primary: bool, order: int, lazy: bool}
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
            'lazy' => $this->lazy,
        ];
    }

    /**
     * @param  array{method: string, returns: string, name: string|null, scope: string, primary: bool, order: int, lazy?: bool}  $data
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
            // Absent on a manifest cached before #[Lazy]-on-#[Bean]-method support shipped —
            // default false rather than fatal, so an old cached manifest on disk still loads
            // (see ComponentScanner / Firefly\Container\Attributes\Lazy).
            $data['lazy'] ?? false,
        );
    }
}

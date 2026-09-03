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
         * Captured from #[Primary] on the #[Bean] method: when SEVERAL #[Bean]
         * methods produce the same return type, this marks the one the bare type
         * resolves to, while every candidate stays reachable under its own
         * #[Bean] name. Exactly the role ComponentDescriptor::$primary plays for
         * competing interface implementations in
         * ContainerRegistrar::wireInterfaces().
         *
         * It was inert for a long time — registerBeans() read it NOWHERE, which
         * is half of why two beans of one type used to collapse onto a single
         * binding with no way to tell them apart. It is consulted now; see
         * ContainerRegistrar::registerBeans() for the full rule, including the
         * registration-time errors for shapes #[Primary] cannot rescue.
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
        /**
         * The class types this factory method asks for — the bean graph's edges for the #[Bean] path.
         *
         * Most of a framework's wiring lives HERE rather than in component constructors: an
         * auto-configuration is a #[Configuration] whose #[Bean] methods take their collaborators as
         * parameters. A graph built only from component constructors therefore draws almost no edges at
         * all, which is exactly what it did before this field existed.
         *
         * Last, with a default, so a manifest compiled before the bean graph shipped still rehydrates.
         *
         * @var list<string>
         */
        public array $dependencies = [],
    ) {}

    /**
     * @return array{method: string, returns: string, name: string|null, scope: string, primary: bool, order: int, lazy: bool, dependencies: list<string>}
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
            'dependencies' => $this->dependencies,
        ];
    }

    /**
     * @param  array{method: string, returns: string, name: string|null, scope: string, primary: bool, order: int, lazy?: bool, dependencies?: list<string>}  $data
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
            // Same reasoning as $lazy: absent on a manifest cached before the bean graph shipped.
            $data['dependencies'] ?? [],
        );
    }
}

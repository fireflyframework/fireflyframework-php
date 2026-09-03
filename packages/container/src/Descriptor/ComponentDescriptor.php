<?php

declare(strict_types=1);

namespace Firefly\Container\Descriptor;

use Firefly\Container\Scope;

final readonly class ComponentDescriptor
{
    /**
     * @param  list<class-string>  $interfaces
     * @param  list<BeanDescriptor>  $beans
     */
    public function __construct(
        public string $class,
        public string $stereotype,
        public ?string $name,
        public Scope $scope,
        public bool $primary,
        public int $order,
        public ?string $qualifier,
        public array $interfaces,
        public array $beans,
        public bool $lazy = false,
        /**
         * The class types this component's constructor asks for — the edges of the bean graph.
         *
         * Recorded at scan time, where reflection is already sanctioned, because the alternative is
         * reflecting at request time to answer "what depends on what", which the reflection-free boot
         * contract forbids. Only CLASS and INTERFACE types are kept: a scalar or a builtin is configuration,
         * not a wiring edge, and putting it in the graph would drown the edges that matter.
         *
         * Last, with a default, so a manifest compiled before this field existed still rehydrates.
         *
         * @var list<string>
         */
        public array $dependencies = [],
    ) {}

    /**
     * @return array{
     *     class: string,
     *     stereotype: string,
     *     name: string|null,
     *     scope: string,
     *     primary: bool,
     *     order: int,
     *     qualifier: string|null,
     *     interfaces: list<class-string>,
     *     beans: list<array{method: string, returns: string, name: string|null, scope: string, primary: bool, order: int, lazy: bool}>,
     *     lazy: bool,
     * }
     */
    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'stereotype' => $this->stereotype,
            'name' => $this->name,
            'scope' => $this->scope->name,
            'primary' => $this->primary,
            'order' => $this->order,
            'qualifier' => $this->qualifier,
            'interfaces' => $this->interfaces,
            'beans' => array_map(static fn (BeanDescriptor $b): array => $b->toArray(), $this->beans),
            'lazy' => $this->lazy,
            'dependencies' => $this->dependencies,
        ];
    }

    /**
     * @param array{
     *     class: string,
     *     stereotype: string,
     *     name: string|null,
     *     scope: string,
     *     primary: bool,
     *     order: int,
     *     qualifier: string|null,
     *     interfaces: list<class-string>,
     *     beans: list<array{method: string, returns: string, name: string|null, scope: string, primary: bool, order: int, lazy?: bool}>,
     *     lazy?: bool,
     *     dependencies?: list<string>,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['class'],
            $data['stereotype'],
            $data['name'],
            Scope::fromName($data['scope']),
            $data['primary'],
            $data['order'],
            $data['qualifier'],
            $data['interfaces'],
            array_map(static fn (array $b): BeanDescriptor => BeanDescriptor::fromArray($b), $data['beans']),
            // Absent on a manifest cached before #[Lazy] support shipped — default false rather
            // than fatal, so an old cached manifest on disk still loads (see ComponentScanner /
            // Firefly\Container\Attributes\Lazy).
            $data['lazy'] ?? false,
            // Same reasoning as $lazy above: absent on a manifest cached before the bean graph shipped.
            $data['dependencies'] ?? [],
        );
    }
}

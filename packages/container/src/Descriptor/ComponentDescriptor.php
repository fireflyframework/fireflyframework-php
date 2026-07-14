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
     *     beans: list<array{method: string, returns: string, name: string|null, scope: string, primary: bool, order: int}>,
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
     *     beans: list<array{method: string, returns: string, name: string|null, scope: string, primary: bool, order: int}>,
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
        );
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Container;

use Firefly\Container\Scanner\ComponentManifest;
use Illuminate\Contracts\Container\Container as IlluminateContainer;

final class Container
{
    private const TAG_PREFIX = 'firefly.contract.';

    /** @var array<string, int> */
    private array $orderByClass;

    public function __construct(
        private readonly IlluminateContainer $illuminate,
        ComponentManifest $manifest,
    ) {
        $this->orderByClass = [];
        foreach ($manifest->components as $component) {
            $this->orderByClass[$component->class] = $component->order;
        }
    }

    public function get(string $id): object
    {
        /** @var object */
        return $this->illuminate->make($id);
    }

    public function getByName(string $name): object
    {
        /** @var object */
        return $this->illuminate->make($name);
    }

    public function has(string $id): bool
    {
        return $this->illuminate->bound($id);
    }

    /**
     * @return list<object> implementations of $interface, sorted by #[Order] ascending
     */
    public function getAll(string $interface): array
    {
        $tag = self::TAG_PREFIX.$interface;

        $instances = [];
        foreach ($this->illuminate->tagged($tag) as $instance) {
            /** @var object $instance */
            $instances[] = $instance;
        }

        usort($instances, fn (object $a, object $b): int => $this->orderOf($a) <=> $this->orderOf($b));

        return $instances;
    }

    private function orderOf(object $instance): int
    {
        return $this->orderByClass[$instance::class] ?? 0;
    }
}

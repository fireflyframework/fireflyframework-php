<?php

declare(strict_types=1);

namespace Firefly\Admin\Route;

use Firefly\Web\Route\RouteDescriptor;

final readonly class RouteDetail
{
    /**
     * @param  list<RouteDescriptor>  $registrations
     * @param  list<RouteBinding>  $caller
     * @param  list<RouteBinding>  $injected
     * @param  list<array{status: int, code: string, binding: string, reason: string}>  $failures
     * @param  list<RouteDescriptor>  $siblings
     */
    public function __construct(
        public RouteDescriptor $route,
        public array $registrations,
        public array $caller,
        public array $injected,
        public array $failures,
        public array $siblings,
    ) {}

    /** @return list<RouteBinding> */
    public function arguments(): array
    {
        $arguments = [...$this->caller, ...$this->injected];
        usort($arguments, static fn (RouteBinding $a, RouteBinding $b): int => $a->position <=> $b->position);

        return $arguments;
    }
}

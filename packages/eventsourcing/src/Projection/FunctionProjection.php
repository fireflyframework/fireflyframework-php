<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Projection;

use Closure;
use Firefly\EventSourcing\Event\StoredEvent;

/**
 * A projection from a closure (PyFly's FunctionProjection): `new FunctionProjection('orders.count', fn (StoredEvent
 * $e) => ..., ['order.placed'], fn () => ...)`. Register it on the ProjectionRegistry bean from a service provider.
 */
final class FunctionProjection implements Projection
{
    /**
     * @param  Closure(StoredEvent): void  $handler
     * @param  list<string>  $handles
     * @param  (Closure(): void)|null  $reset
     */
    public function __construct(
        private readonly string $name,
        private readonly Closure $handler,
        private readonly array $handles = [],
        private readonly ?Closure $reset = null,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function handles(): array
    {
        return $this->handles;
    }

    public function handle(StoredEvent $event): void
    {
        ($this->handler)($event);
    }

    public function reset(): void
    {
        if ($this->reset !== null) {
            ($this->reset)();
        }
    }
}

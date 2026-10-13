<?php

declare(strict_types=1);

use Firefly\Domain\DomainEvent;
use Firefly\EventSourcing\Aggregate\EventSourcedAggregateRoot;
use Firefly\EventSourcing\Tests\Fixtures\Aggregate\Event;
use Firefly\EventSourcing\Tests\Fixtures\Events\OrderPaid;
use Firefly\EventSourcing\Tests\Fixtures\Events\OrderPlaced;
use Firefly\EventSourcing\Tests\Fixtures\Order;

it('applies what it records through the apply<ShortName> convention and counts uncommitted events', function (): void {
    $order = Order::place('o-1', 12.5);
    $order->pay(1000);

    expect([$order->total(), $order->paidCents(), $order->version(), $order->committedVersion()])->toBe([12.5, 1000, 2, 0])
        ->and(array_map(static fn (DomainEvent $e): string => $e::class, $order->uncommittedEvents()))->toBe([OrderPlaced::class, OrderPaid::class]);

    $order->markEventsCommitted();

    expect([$order->version(), $order->committedVersion(), $order->uncommittedEvents()])->toBe([2, 2, []])
        ->and(Order::aggregateType())->toBe('order');
});

it('replays history and a snapshot without recording anything', function (): void {
    $replayed = Order::reconstitute('o-1', [new OrderPlaced('o-1', 20.0), new OrderPaid('o-1', 500)]);
    $fromSnapshot = Order::reconstitute('o-1', [new OrderPaid('o-1', 1500)], 7, ['total' => 20.0, 'paidCents' => 500]);

    expect([$replayed->paidCents(), $replayed->committedVersion(), $replayed->uncommittedEvents()])->toBe([500, 2, []])
        ->and([$fromSnapshot->total(), $fromSnapshot->paidCents(), $fromSnapshot->committedVersion(), $fromSnapshot->isPaid()])->toBe([20.0, 2000, 8, true]);
});

it('routes an event to a handler named in eventHandlers(), ignores an event with no handler, and refuses a private handler', function (): void {
    $aggregate = new class('x-1') extends EventSourcedAggregateRoot
    {
        public float $seen = 0.0;

        public function place(): void
        {
            $this->recordThat(new OrderPlaced('x-1', 3.0));
            $this->recordThat(new OrderPaid('x-1', 1));
        }

        protected function eventHandlers(): array
        {
            return [OrderPlaced::class => 'whenPlaced'];
        }

        protected function whenPlaced(OrderPlaced $event): void
        {
            $this->seen = $event->total;
        }
    };
    $aggregate->place();

    $private = new class('y-1') extends EventSourcedAggregateRoot
    {
        public function place(): void
        {
            $this->recordThat(new OrderPlaced('y-1', 1.0));
        }

        /** The aggregate may call its own private handler; the base may not. */
        public function applyDirectly(OrderPlaced $event): void
        {
            $this->applyOrderPlaced($event);
        }

        private function applyOrderPlaced(OrderPlaced $event): void {}
    };

    expect([$aggregate->seen, $aggregate->version()])->toBe([3.0, 2])
        ->and(fn () => $private->place())->toThrow(LogicException::class, 'protected');
});

it('routes an event short-named Event to the aggregate own applyEvent handler', function (): void {
    $aggregate = new class('z-1') extends EventSourcedAggregateRoot
    {
        public string $note = '';

        public function note(string $note): void
        {
            $this->recordThat(new Event($note));
        }

        protected function applyEvent(Event $event): void
        {
            $this->note = $event->note;
        }
    };
    $aggregate->note('hello');

    expect([$aggregate->note, $aggregate->version()])->toBe(['hello', 1]);
});

it('refuses a handler that eventHandlers() names but the aggregate does not declare', function (): void {
    $aggregate = new class('w-1') extends EventSourcedAggregateRoot
    {
        public function place(): void
        {
            $this->recordThat(new OrderPlaced('w-1', 1.0));
        }

        protected function eventHandlers(): array
        {
            return [OrderPlaced::class => 'whenPlacd'];
        }
    };

    expect(fn () => $aggregate->place())->toThrow(LogicException::class, 'whenPlacd');
});

<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Data\Repository\Pageable;
use Firefly\Data\Repository\Specification\Specifications;
use Firefly\Data\Tests\Fixtures\Ordering\Order;
use Firefly\Data\Tests\Fixtures\Ordering\OrderPlaced;
use Firefly\Data\Tests\Fixtures\Ordering\OrderRepository;
use Firefly\Data\Tests\Fixtures\Ordering\PlaceOrderService;
use Firefly\Data\Tests\Support\MilestoneCapstoneTestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Application;

uses(MilestoneCapstoneTestCase::class);

/**
 * Resolve the (proxied) PlaceOrderService from the booted context. Takes the app explicitly — a top-level Pest
 * helper is NOT bound to the TestCase, so it cannot read the protected $this->app itself; each it() passes it in
 * via $this->app() (inside an it() closure $this IS the TestCase, and app() narrows the untyped
 * inherited $app to a real Application).
 */
function placeOrderService(Application $app): PlaceOrderService
{
    /** @var ApplicationContext $context */
    $context = $app->make(ApplicationContext::class);

    /** @var PlaceOrderService $service */
    $service = $context->get(PlaceOrderService::class);

    return $service;
}

function orderRepository(Application $app): OrderRepository
{
    /** @var ApplicationContext $context */
    $context = $app->make(ApplicationContext::class);

    /** @var OrderRepository $repo */
    $repo = $context->get(OrderRepository::class);

    return $repo;
}

/**
 * Narrow a spied `object` to the concrete OrderPlaced type before reading its ->status — same
 * instanceof-narrow-or-throw shape as AfterCommitDispatchTest's asItemAdded/asNoteAdded (no @var/@phpstan-var
 * override, no cast).
 */
function asOrderPlaced(object $event): OrderPlaced
{
    if (! $event instanceof OrderPlaced) {
        throw new RuntimeException('Expected an OrderPlaced event.');
    }

    return $event;
}

it('(1) proxies the #[Transactional] service and rolls back the whole unit of work — no rows, no event', function () {
    /** @var MilestoneCapstoneTestCase $this */
    $service = placeOrderService($this->app());

    expect($service::class)->not->toBe(PlaceOrderService::class) // it IS the generated proxy subclass
        ->and($service)->toBeInstanceOf(PlaceOrderService::class);

    try {
        $service->placeOrderAndFail('placed');
    } catch (RuntimeException) {
    }

    expect(Order::query()->count())->toBe(0)
        ->and($this->spy->events)->toBe([]);
});

it('(2) returns the derived-query rows (findByStatusOrderByCreatedAtDesc) after commit', function () {
    /** @var MilestoneCapstoneTestCase $this */
    $service = placeOrderService($this->app());
    $service->placeOrder('placed');   // created_at ...:01
    $service->placeOrder('placed');   // created_at ...:02
    $service->placeOrder('shipped');  // filtered out

    $rows = orderRepository($this->app())->findByStatusOrderByCreatedAtDesc('placed');

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->id)->toBeGreaterThan($rows[1]->id) // created_at DESC ⇒ latest first
        ->and(array_map(static fn (Order $r): string => (string) $r->status, $rows))->toBe(['placed', 'placed']);
});

it('(3) publishes the domain event to the spy ONLY after a real commit; rollback publishes nothing', function () {
    /** @var MilestoneCapstoneTestCase $this */
    $service = placeOrderService($this->app());

    $service->placeOrder('placed');

    expect($this->spy->events)->toHaveCount(1)
        ->and($this->spy->events[0])->toBeInstanceOf(OrderPlaced::class)
        ->and(asOrderPlaced($this->spy->events[0])->status)->toBe('placed');

    try {
        $service->placeOrderAndFail('placed');
    } catch (RuntimeException) {
    }

    expect($this->spy->events)->toHaveCount(1); // the rolled-back unit of work published nothing
});

it('(4) returns the right Page from a Specification-filtered paged query', function () {
    /** @var MilestoneCapstoneTestCase $this */
    $service = placeOrderService($this->app());
    $service->placeOrder('placed');
    $service->placeOrder('placed');
    $service->placeOrder('placed');
    $service->placeOrder('cancelled');

    $page = orderRepository($this->app())->findBySpecificationPaged(
        Specifications::where(fn (Builder $q) => $q->where('status', 'placed')),
        Pageable::of(1, 2),
    );

    expect($page->total)->toBe(3)
        ->and($page->items)->toHaveCount(2)
        ->and($page->totalPages())->toBe(2)
        ->and($page->hasNext())->toBeTrue();
});

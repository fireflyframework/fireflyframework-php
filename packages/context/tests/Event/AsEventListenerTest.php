<?php

declare(strict_types=1);

use Firefly\Context\Event\AsEventListener;

/**
 * #[AsEventListener] is INERT METADATA ONLY — same rule as every other Firefly attribute (see e.g.
 * Firefly\Context\Lifecycle\PostConstruct). It carries no dispatch/registration logic; a later boot
 * pass (RegisterEventListenersPass, M4 Task 11 — not built here) supplies all discovery and wiring.
 */
it('declares #[AsEventListener] targeting methods only, repeatable, final readonly', function () {
    $reflection = new ReflectionClass(AsEventListener::class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue();

    $attributes = $reflection->getAttributes(Attribute::class);
    expect($attributes)->toHaveCount(1);

    /** @var Attribute $meta */
    $meta = $attributes[0]->newInstance();

    expect($meta->flags & Attribute::TARGET_METHOD)->not->toBe(0)
        ->and($meta->flags & Attribute::TARGET_CLASS)->toBe(0)
        ->and($meta->flags & Attribute::IS_REPEATABLE)->not->toBe(0);
});

it('defaults #[AsEventListener] to event: null (infer from the first parameter) and order: 0', function () {
    $attr = new AsEventListener;

    expect($attr->event)->toBeNull()
        ->and($attr->order)->toBe(0);
});

it('constructs #[AsEventListener] with an explicit event and order', function () {
    $attr = new AsEventListener(event: 'App\Events\OrderPlaced', order: 5);

    expect($attr->event)->toBe('App\Events\OrderPlaced')
        ->and($attr->order)->toBe(5);
});

it('supports being repeated on the same method (multiple #[AsEventListener] attributes)', function () {
    $reflection = new ReflectionMethod(MultiListenerFixture::class, 'handle');
    $attributes = $reflection->getAttributes(AsEventListener::class);

    expect($attributes)->toHaveCount(2);

    $first = $attributes[0]->newInstance();
    $second = $attributes[1]->newInstance();

    expect($first->event)->toBe('App\Events\OrderPlaced')
        ->and($second->event)->toBe('App\Events\OrderCancelled');
});

final class MultiListenerFixture
{
    #[AsEventListener(event: 'App\Events\OrderPlaced')]
    #[AsEventListener(event: 'App\Events\OrderCancelled')]
    public function handle(): void {}
}

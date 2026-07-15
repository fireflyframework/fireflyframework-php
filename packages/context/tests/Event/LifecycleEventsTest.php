<?php

declare(strict_types=1);

use Firefly\Context\Event\ApplicationReadyEvent;
use Firefly\Context\Event\ContextClosedEvent;
use Firefly\Context\Event\ContextRefreshedEvent;

/**
 * The three lifecycle events are FLAT `final readonly` classes with NO common base class.
 *
 * Illuminate\Events\Dispatcher::getListeners() matches listeners on the event's CONCRETE class name
 * (and interfaces, via class_implements()) — it never walks class_parents(). A shared
 * `ApplicationEvent` base "for tidiness" would create a listener-matching trap: a listener registered
 * against the base class would silently never fire for any of these. Keeping the three classes flat
 * and unrelated makes that bug structurally impossible, so this test also asserts the absence of any
 * common ancestor.
 */
it('constructs ContextRefreshedEvent and carries its data', function () {
    $at = new DateTimeImmutable('2026-07-15T10:00:00+00:00');
    $event = new ContextRefreshedEvent($at);

    expect($event->refreshedAt)->toBe($at);
});

it('constructs ApplicationReadyEvent and carries its data', function () {
    $at = new DateTimeImmutable('2026-07-15T10:00:01+00:00');
    $event = new ApplicationReadyEvent($at);

    expect($event->readyAt)->toBe($at);
});

it('constructs ContextClosedEvent and carries its data', function () {
    $at = new DateTimeImmutable('2026-07-15T10:00:02+00:00');
    $event = new ContextClosedEvent($at);

    expect($event->closedAt)->toBe($at);
});

it('declares all three lifecycle events as final readonly, with no common base class', function () {
    $classes = [
        ContextRefreshedEvent::class,
        ApplicationReadyEvent::class,
        ContextClosedEvent::class,
    ];

    // Each is `final` (PHPStan/reflection already guarantee none can be a subclass of another —
    // that guarantee IS the fix) and has no parent class of its own: no shared "ApplicationEvent"
    // base exists for a listener to be mis-registered against.
    foreach ($classes as $class) {
        $reflection = new ReflectionClass($class);

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->isReadOnly())->toBeTrue();

        $parent = $reflection->getParentClass();
        expect($parent)->toBeFalse();
    }
});

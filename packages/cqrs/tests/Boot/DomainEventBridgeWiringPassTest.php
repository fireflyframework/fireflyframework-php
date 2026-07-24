<?php

declare(strict_types=1);

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Cqrs\Event\DomainEventBridge;
use Firefly\Cqrs\Tests\EventFixtures\AccountOpened;
use Firefly\Cqrs\Tests\EventFixtures\RecordingCommandEventPublisher;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;

function bootBridgedApp(RecordingCommandEventPublisher $publisher): Application
{
    $app = new Application;
    $app->instance('config', new Repository(['firefly' => ['cqrs' => []]]));

    // Bind the bridge (wrapping the recording publisher) BEFORE boot; the wildcard listener resolves it fresh.
    $app->instance(DomainEventBridge::class, new DomainEventBridge($publisher));

    $app->register(new FireflyAutoConfigureServiceProvider($app));
    $app->register(new CqrsServiceProvider($app));
    $app->register(new CqrsWiringProvider($app));

    $app->boot();

    return $app;
}

it('re-emits ANY DomainEvent SUBCLASS dispatched in-process (the base-class listener trap is avoided)', function () {
    $publisher = new RecordingCommandEventPublisher;
    $app = bootBridgedApp($publisher);

    // AccountOpened is a *subclass* of DomainEvent; a listener typed on the base class would never match it on
    // Laravel's dispatcher (concrete-class + interfaces only). The wildcard + instanceof trigger DOES.
    $app->make(Dispatcher::class)->dispatch(new AccountOpened('a1', 100));

    expect($publisher->published)->toHaveCount(1)
        ->and($publisher->published[0])->toBeInstanceOf(AccountOpened::class);
});

it('ignores a non-DomainEvent event (the instanceof filter gates the wildcard)', function () {
    $publisher = new RecordingCommandEventPublisher;
    $app = bootBridgedApp($publisher);

    $app->make(Dispatcher::class)->dispatch(new stdClass);
    $app->make(Dispatcher::class)->dispatch('some.string.event', ['payload']);

    expect($publisher->published)->toBe([]); // neither is a DomainEvent
});

it('resolves the DomainEventBridge FRESH on every dispatch — a rebind after boot is honoured (proxy-safety)', function () {
    $first = new RecordingCommandEventPublisher;
    $app = bootBridgedApp($first);

    $app->make(Dispatcher::class)->dispatch(new AccountOpened('a1', 100));
    expect($first->published)->toHaveCount(1);

    // Rebind the bridge to a SECOND publisher AFTER boot. Because the wildcard closure resolves DomainEventBridge
    // per dispatch, the next event must reach the REBOUND bridge — a closure that cached the bridge at pass-run
    // time (resolving it ONCE) would still hit $first.
    $second = new RecordingCommandEventPublisher;
    $app->instance(DomainEventBridge::class, new DomainEventBridge($second));

    $app->make(Dispatcher::class)->dispatch(new AccountOpened('a2', 200));

    expect($first->published)->toHaveCount(1)        // first bridge NOT hit again
        ->and($second->published)->toHaveCount(1)    // the rebound bridge IS hit
        ->and($second->published[0])->toBeInstanceOf(AccountOpened::class);
});

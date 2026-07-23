<?php

declare(strict_types=1);

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Eda\EdaServiceProvider;
use Firefly\Eda\EdaWiringProvider;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Listener\EventListenerManifest;
use Firefly\Eda\Scanner\EventListenerScanner;
use Firefly\Eda\Tests\Fixtures\Spy;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

/**
 * REAL-PROVIDER wiring: the shipped EdaServiceProvider (candidacy) + EdaWiringProvider (passes) + bootstrap
 * assemble over the live boot pipeline. A one-row manifest (compiled inline by the real scanner over the fixtures)
 * must be subscribed onto the auto-config-bound InMemoryEventBus at boot, so a later publish reaches the listener.
 */
it('subscribes compiled #[EventListener]s onto the bus at boot (in-memory provider)', function () {
    $app = new Application;
    $app->instance('config', new Repository(['firefly' => ['eda' => []]]));

    // Compile the fixtures inline (exactly what firefly:cache emits, M15) and bind the manifest + a shared Spy.
    $descriptors = (new EventListenerScanner)->scan(['Firefly\\Eda\\Tests\\Fixtures\\' => dirname(__DIR__).'/Fixtures']);
    $app->instance(EventListenerManifest::class, new EventListenerManifest($descriptors));
    $app->singleton(Spy::class);

    $app->register(new FireflyAutoConfigureServiceProvider($app));
    $app->register(new EdaServiceProvider($app));
    $app->register(new EdaWiringProvider($app));

    $app->boot();

    /** @var EventPublisher $bus */
    $bus = $app->make(EventPublisher::class);
    $bus->publish('firefly.events', 'order.placed', ['id' => 1]);

    expect($app->make(Spy::class)->seen)->toBe(['order.placed']); // fail.* listener never matched
});

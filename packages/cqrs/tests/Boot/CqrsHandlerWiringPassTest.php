<?php

declare(strict_types=1);

use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Cqrs\Handler\HandlerDescriptor;
use Firefly\Cqrs\Handler\HandlerKind;
use Firefly\Cqrs\Handler\HandlerManifest;
use Firefly\Cqrs\Handler\HandlerRegistry;
use Firefly\Cqrs\Tests\WiringFixtures\Ping;
use Firefly\Cqrs\Tests\WiringFixtures\PingHandler;
use Firefly\Cqrs\Tests\WiringFixtures\Pong;
use Firefly\Cqrs\Tests\WiringFixtures\PongHandler;
use Illuminate\Foundation\Application;

function bootWiredApp(HandlerManifest $manifest): Application
{
    // A real compiled manifest + a shared registry singleton, bound BEFORE the providers register (via the
    // harness's `bindings:` menu) so the wiring provider's bound()-guarded default backs off and the wiring pass
    // populates THIS registry.
    return fireflyApplication(
        ['firefly' => ['cqrs' => []]],
        [CqrsServiceProvider::class, CqrsWiringProvider::class],
        bindings: [
            HandlerManifest::class => $manifest,
            HandlerRegistry::class => new HandlerRegistry,
        ],
    );
}

it('populates the registry from the manifest so command AND query handlers are dispatchable after boot', function () {
    $manifest = new HandlerManifest(
        [
            new HandlerDescriptor(Ping::class, PingHandler::class, 'handle', HandlerKind::Command),
            new HandlerDescriptor(Pong::class, PongHandler::class, 'handle', HandlerKind::Query),
        ],
        [],
    );

    $app = bootWiredApp($manifest);

    /** @var HandlerRegistry $registry */
    $registry = $app->make(HandlerRegistry::class);

    expect($registry->hasCommandHandler(Ping::class))->toBeTrue()
        ->and($registry->hasQueryHandler(Pong::class))->toBeTrue()    // routed to the QUERY map by HandlerKind
        ->and($registry->hasCommandHandler(Pong::class))->toBeFalse()  // NOT mis-routed into the command map
        ->and(($registry->findCommandHandler(Ping::class))(new Ping))->toBe('default')
        ->and(($registry->findQueryHandler(Pong::class))(new Pong))->toBe('pong');
});

it('resolves the handler bean FRESH on every dispatch (proxy-safety), never caching it at registration', function () {
    $manifest = new HandlerManifest(
        [new HandlerDescriptor(Ping::class, PingHandler::class, 'handle', HandlerKind::Command)],
        [],
    );

    $app = bootWiredApp($manifest);

    /** @var HandlerRegistry $registry */
    $registry = $app->make(HandlerRegistry::class);
    $invoker = $registry->findCommandHandler(Ping::class);

    expect($invoker(new Ping))->toBe('default'); // autowired instance

    // Swap the container binding AFTER wiring — as the M8 TransactionalBeanPostProcessor swaps a #[Transactional]
    // bean for its proxy. A fresh-per-dispatch invoker observes the swap; a cached one would still return 'default'.
    $app->instance(PingHandler::class, new PingHandler('proxied'));

    expect($invoker(new Ping))->toBe('proxied');
});

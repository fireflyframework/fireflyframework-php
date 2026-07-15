<?php

declare(strict_types=1);

use Firefly\Container\Container as FireflyContainer;
use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Container\Scope;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Context\Event\ContextClosedEvent;
use Firefly\Context\Event\DispatcherEventPublisher;
use Firefly\Context\Lifecycle\DisposableBeanRegistry;
use Firefly\Context\Lifecycle\InitDestroyInvoker;
use Firefly\Context\Lifecycle\LifecycleRegistry;
use Firefly\Context\Lifecycle\PreDestroy;
use Firefly\Context\Scanner\ContextDescriptor;
use Firefly\Context\Scanner\ContextManifest;
use Firefly\Kernel\Lifecycle;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;

/**
 * ApplicationContext is exercised through REAL collaborators — a REAL Firefly\Container\Container
 * facade, a REAL DispatcherEventPublisher over a REAL Illuminate\Events\Dispatcher, a REAL
 * DisposableBeanRegistry/InitDestroyInvoker, and REAL Lifecycle fixtures — never mocks of our own
 * interfaces.
 */
final class ContextLog
{
    /** @var list<string> */
    public array $entries = [];

    public function record(string $entry): void
    {
        $this->entries[] = $entry;
    }
}

final class DisposableContextService
{
    public function __construct(private readonly ContextLog $log, private readonly string $id) {}

    #[PreDestroy]
    public function shutdown(): void
    {
        $this->log->record("destroy:{$this->id}");
    }
}

final class InfraContextComponent implements Lifecycle
{
    public function __construct(private readonly ContextLog $log, private readonly string $id) {}

    public function start(): void
    {
        $this->log->record("start:{$this->id}");
    }

    public function stop(): void
    {
        $this->log->record("stop:{$this->id}");
    }
}

final class ProbeEvent {}

interface DemoContract {}

final class DemoService implements DemoContract {}

final class DemoServiceLow implements DemoContract {}

final class DemoServiceHigh implements DemoContract {}

/**
 * @param  list<class-string>  $interfaces
 */
function demoDescriptor(string $class, int $order = 0, array $interfaces = []): ComponentDescriptor
{
    return new ComponentDescriptor(
        class: $class,
        stereotype: 'Service',
        name: null,
        scope: Scope::Singleton,
        primary: false,
        order: $order,
        qualifier: null,
        interfaces: $interfaces,
        beans: [],
    );
}

function contextIlluminateContainer(): Container
{
    $container = new Container;
    $container->instance('events', new Dispatcher($container));

    return $container;
}

/**
 * Hand-built ContextManifest standing in for a real ContextScanner scan: DisposableContextService
 * is declared inline in this test file (not under a scannable PSR-4 directory).
 */
function disposableContextManifest(): ContextManifest
{
    return new ContextManifest([
        new ContextDescriptor(class: DisposableContextService::class, preDestroy: ['shutdown']),
    ]);
}

function makeApplicationContext(
    Container $illuminate,
    ComponentManifest $manifest,
    DisposableBeanRegistry $disposables,
    LifecycleRegistry $lifecycles,
): ApplicationContext {
    return new ApplicationContext(
        new FireflyContainer($illuminate, $manifest),
        new DispatcherEventPublisher($illuminate),
        $disposables,
        $lifecycles,
    );
}

// --- get()/getByName()/has()/getAll() delegate to the Firefly\Container\Container facade ---

it('get()/getByName()/has() delegate to the Firefly\Container\Container facade', function () {
    $illuminate = contextIlluminateContainer();
    $illuminate->singleton(DemoService::class, DemoService::class);

    $manifest = new ComponentManifest([demoDescriptor(DemoService::class)]);
    $context = makeApplicationContext(
        $illuminate,
        $manifest,
        new DisposableBeanRegistry(new InitDestroyInvoker($illuminate)),
        new LifecycleRegistry,
    );

    expect($context->has(DemoService::class))->toBeTrue()
        ->and($context->get(DemoService::class))->toBeInstanceOf(DemoService::class)
        ->and($context->getByName(DemoService::class))->toBeInstanceOf(DemoService::class);
});

it('getAll() delegates to the facade, honoring its #[Order] semantics', function () {
    $illuminate = contextIlluminateContainer();

    $illuminate->singleton(DemoServiceLow::class, DemoServiceLow::class);
    $illuminate->singleton(DemoServiceHigh::class, DemoServiceHigh::class);
    $illuminate->tag([DemoServiceHigh::class, DemoServiceLow::class], 'firefly.contract.'.DemoContract::class);

    $manifest = new ComponentManifest([
        demoDescriptor(DemoServiceLow::class, order: 1, interfaces: [DemoContract::class]),
        demoDescriptor(DemoServiceHigh::class, order: 9, interfaces: [DemoContract::class]),
    ]);
    $context = makeApplicationContext(
        $illuminate,
        $manifest,
        new DisposableBeanRegistry(new InitDestroyInvoker($illuminate)),
        new LifecycleRegistry,
    );

    $all = $context->getAll(DemoContract::class);

    expect($all)->toHaveCount(2)
        ->and($all[0])->toBeInstanceOf(DemoServiceLow::class)
        ->and($all[1])->toBeInstanceOf(DemoServiceHigh::class);
});

// --- publishEvent() delegates to the ApplicationEventPublisher ---

it('publishEvent() delegates to the ApplicationEventPublisher', function () {
    $illuminate = contextIlluminateContainer();
    $received = null;
    /** @var Dispatcher $dispatcher */
    $dispatcher = $illuminate->make('events');
    $dispatcher->listen(ProbeEvent::class, function (ProbeEvent $event) use (&$received): void {
        $received = $event;
    });

    $context = makeApplicationContext(
        $illuminate,
        new ComponentManifest([]),
        new DisposableBeanRegistry(new InitDestroyInvoker($illuminate)),
        new LifecycleRegistry,
    );

    $event = new ProbeEvent;
    $context->publishEvent($event);

    expect($received)->toBe($event);
});

// --- isActive()/close() ---

it('close() publishes ContextClosedEvent', function () {
    $illuminate = contextIlluminateContainer();
    $received = null;
    /** @var Dispatcher $dispatcher */
    $dispatcher = $illuminate->make('events');
    $dispatcher->listen(ContextClosedEvent::class, function (ContextClosedEvent $event) use (&$received): void {
        $received = $event;
    });

    $context = makeApplicationContext(
        $illuminate,
        new ComponentManifest([]),
        new DisposableBeanRegistry(new InitDestroyInvoker($illuminate)),
        new LifecycleRegistry,
    );

    $context->close();

    expect($received)->toBeInstanceOf(ContextClosedEvent::class);
});

it('close() runs #[PreDestroy] beans and Lifecycle::stop() in REVERSE order, then marks the context inactive', function () {
    $illuminate = contextIlluminateContainer();
    $log = new ContextLog;

    $disposables = new DisposableBeanRegistry(new InitDestroyInvoker($illuminate, disposableContextManifest()));
    $first = new DisposableContextService($log, 'first');
    $second = new DisposableContextService($log, 'second');
    $disposables->register($first, DisposableContextService::class, Scope::Singleton);
    $disposables->register($second, DisposableContextService::class, Scope::Singleton);

    $lifecycles = new LifecycleRegistry;
    $lifecycles->add(new InfraContextComponent($log, 'A'));
    $lifecycles->add(new InfraContextComponent($log, 'B'));

    $context = makeApplicationContext($illuminate, new ComponentManifest([]), $disposables, $lifecycles);

    expect($context->isActive())->toBeTrue();

    $context->close();

    expect($log->entries)->toBe(['destroy:second', 'destroy:first', 'stop:B', 'stop:A'])
        ->and($context->isActive())->toBeFalse();
});

it('close() is idempotent: a second call re-runs nothing', function () {
    $illuminate = contextIlluminateContainer();
    $log = new ContextLog;

    $disposables = new DisposableBeanRegistry(new InitDestroyInvoker($illuminate, disposableContextManifest()));
    // Held in a local var deliberately: DisposableBeanRegistry holds only a \WeakReference, so
    // without a surviving strong reference here PHP's refcounting would free the bean the instant
    // register() returns — before close() ever ran (see DisposableBeanRegistryTest).
    $bean = new DisposableContextService($log, 'once');
    $disposables->register($bean, DisposableContextService::class, Scope::Singleton);

    $lifecycles = new LifecycleRegistry;
    $lifecycles->add(new InfraContextComponent($log, 'once'));

    $context = makeApplicationContext($illuminate, new ComponentManifest([]), $disposables, $lifecycles);

    $context->close();
    $context->close();

    expect($log->entries)->toBe(['destroy:once', 'stop:once']);
});

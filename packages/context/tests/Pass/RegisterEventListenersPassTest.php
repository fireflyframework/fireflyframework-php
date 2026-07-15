<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Container\Descriptor\BeanDescriptor;
use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scope;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinition;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Firefly\Context\Event\AsEventListener;
use Firefly\Context\Pass\RegisterEventListenersPass;
use Firefly\Context\Scanner\ContextDescriptor;
use Firefly\Context\Scanner\ContextManifest;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Events\Dispatcher as IlluminateDispatcher;

/**
 * RegisterEventListenersPass is exercised against a REAL Illuminate\Events\Dispatcher over a REAL
 * Illuminate\Container\Container — never a mock of our own interfaces. This is also the home of THE
 * BLOCKING REQUIREMENT test: guardListener() must be PROVEN wired end-to-end here, not merely
 * present and unit-tested in isolation (see DispatcherEventPublisherTest for the isolated unit test
 * of guardListener() itself).
 */
final class ListenerPassLog
{
    /** @var list<string> */
    public array $entries = [];

    public function record(string $entry): void
    {
        $this->entries[] = $entry;
    }
}

final class ListenerHaltEvent
{
    public function __construct(public string $tag) {}
}

final class FalseReturningListener
{
    public function __construct(private readonly ListenerPassLog $log) {}

    #[AsEventListener(order: 0)]
    public function onHalt(ListenerHaltEvent $event): bool
    {
        $this->log->record('first');

        // Deliberately falsy — a naive listener that happens to return the result of some other
        // call. Without the guard, Illuminate's dispatch loop breaks HERE and SecondListener below
        // never runs, regardless of $halt.
        return false;
    }
}

final class SecondListener
{
    public function __construct(private readonly ListenerPassLog $log) {}

    #[AsEventListener(order: 10)]
    public function onHalt(ListenerHaltEvent $event): void
    {
        $this->log->record('second');
    }
}

final class OrderedFirstListener
{
    public function __construct(private readonly ListenerPassLog $log) {}

    #[AsEventListener(order: 1)]
    public function onHalt(ListenerHaltEvent $event): void
    {
        $this->log->record('ordered-first');
    }
}

final class OrderedSecondListener
{
    public function __construct(private readonly ListenerPassLog $log) {}

    #[AsEventListener(order: 20)]
    public function onHalt(ListenerHaltEvent $event): void
    {
        $this->log->record('ordered-second');
    }
}

/**
 * M4 review #5, Minor 5: the canonical shape a `#[Bean]` factory produces — never itself a
 * `#[Component]`/`#[Configuration]`, only ever reachable via the FACTORY's declaring class
 * (`BeanFactoryConfigFixture` below, never referenced directly by orderedListeners()).
 */
final class BeanProducedListener
{
    public function __construct(private readonly ListenerPassLog $log) {}

    #[AsEventListener(order: 0)]
    public function onHalt(ListenerHaltEvent $event): void
    {
        $this->log->record('bean-produced');
    }
}

function listenerDescriptor(string $class): ComponentDescriptor
{
    return new ComponentDescriptor(
        class: $class,
        stereotype: 'Service',
        name: null,
        scope: Scope::Singleton,
        primary: false,
        order: 0,
        qualifier: null,
        interfaces: [],
        beans: [],
    );
}

/**
 * Hand-built ContextManifest standing in for a real ContextScanner scan: these listener fixtures
 * are declared inline in this test file (not under a scannable PSR-4 directory), matching the
 * established pattern in this suite of hand-building ComponentDescriptor instead of scanning.
 */
function listenerContextManifest(): ContextManifest
{
    return new ContextManifest([
        new ContextDescriptor(
            class: FalseReturningListener::class,
            listeners: [['method' => 'onHalt', 'event' => ListenerHaltEvent::class, 'order' => 0]],
        ),
        new ContextDescriptor(
            class: SecondListener::class,
            listeners: [['method' => 'onHalt', 'event' => ListenerHaltEvent::class, 'order' => 10]],
        ),
        new ContextDescriptor(
            class: OrderedFirstListener::class,
            listeners: [['method' => 'onHalt', 'event' => ListenerHaltEvent::class, 'order' => 1]],
        ),
        new ContextDescriptor(
            class: OrderedSecondListener::class,
            listeners: [['method' => 'onHalt', 'event' => ListenerHaltEvent::class, 'order' => 20]],
        ),
        // Keyed by the PRODUCED class (BeanProducedListener), exactly as a real ContextScanner scan
        // would capture it — never by the #[Bean] factory's declaring class ('App\BeanFactoryConfig'
        // below, which never appears as a manifest key at all).
        new ContextDescriptor(
            class: BeanProducedListener::class,
            listeners: [['method' => 'onHalt', 'event' => ListenerHaltEvent::class, 'order' => 0]],
        ),
    ]);
}

function listenerContext(): BootContext
{
    $config = new Config(new Repository([]));
    $profiles = new Profiles([]);
    $container = new Container;
    $container->instance('events', new IlluminateDispatcher($container));

    return new BootContext(
        container: $container,
        definitions: new BeanDefinitionRegistry,
        config: $config,
        profiles: $profiles,
        conditions: new ConditionEvaluator($config, $profiles),
        report: new ConditionEvaluationReport,
        contextManifest: listenerContextManifest(),
    );
}

// --- 🔴 THE BLOCKING REQUIREMENT: guardListener() wired end-to-end ---

it('wires guardListener(): a registered listener returning false does NOT stop a later registered listener from running', function () {
    $context = listenerContext();
    $log = new ListenerPassLog;
    $context->container->instance(ListenerPassLog::class, $log);

    $context->definitions->add(new BeanDefinition(listenerDescriptor(FalseReturningListener::class)));
    $context->definitions->add(new BeanDefinition(listenerDescriptor(SecondListener::class)));

    (new RegisterEventListenersPass)->run($context);

    /** @var Dispatcher $dispatcher */
    $dispatcher = $context->container->make('events');
    $dispatcher->dispatch(new ListenerHaltEvent('probe'));

    // If guardListener() were NOT wired, Illuminate's dispatch loop would break the instant
    // FalseReturningListener returns false, and 'second' would never be recorded — every unit test
    // of guardListener() in isolation would still pass green while this exact bug ships live.
    expect($log->entries)->toBe(['first', 'second']);
});

// --- listeners run in #[Order], regardless of manifest/definition registration order ---

it('registers listeners in #[Order] read from the manifest, regardless of definition registration order', function () {
    $context = listenerContext();
    $log = new ListenerPassLog;
    $context->container->instance(ListenerPassLog::class, $log);

    // Registered in the REVERSE of the intended dispatch order.
    $context->definitions->add(new BeanDefinition(listenerDescriptor(OrderedSecondListener::class)));
    $context->definitions->add(new BeanDefinition(listenerDescriptor(OrderedFirstListener::class)));

    (new RegisterEventListenersPass)->run($context);

    /** @var Dispatcher $dispatcher */
    $dispatcher = $context->container->make('events');
    $dispatcher->dispatch(new ListenerHaltEvent('probe'));

    expect($log->entries)->toBe(['ordered-first', 'ordered-second']);
});

// --- M4 review #5 (Minor 5): #[AsEventListener] on a #[Bean]-PRODUCED class, not just a component's ---
// --- own class — this pass used to look up ONLY $definition->class(), silently never finding the ---
// --- manifest entry keyed under the produced type. ---

it('registers a listener declared on a #[Bean]-PRODUCED class, not just on the #[Configuration]\'s own declaring class', function () {
    $context = listenerContext();
    $log = new ListenerPassLog;
    $context->container->instance(ListenerPassLog::class, $log);

    // The #[Configuration]-shaped definition's OWN class ('App\BeanFactoryConfig') has NO manifest
    // entry at all — only its #[Bean] method's return type (BeanProducedListener) does. Before the
    // fix, orderedListeners() only ever queried forClass($definition->class()) — i.e.
    // forClass('App\BeanFactoryConfig') — which is null, so BeanProducedListener's listener was
    // scanned, stored, and never read.
    $configDescriptor = new ComponentDescriptor(
        class: 'App\BeanFactoryConfig',
        stereotype: 'Configuration',
        name: null,
        scope: Scope::Singleton,
        primary: false,
        order: 0,
        qualifier: null,
        interfaces: [],
        beans: [
            new BeanDescriptor(
                method: 'redisCache',
                returns: BeanProducedListener::class,
                name: null,
                scope: Scope::Singleton,
                primary: false,
                order: 0,
            ),
        ],
    );
    $context->definitions->add(new BeanDefinition($configDescriptor));

    (new RegisterEventListenersPass)->run($context);

    /** @var Dispatcher $dispatcher */
    $dispatcher = $context->container->make('events');
    $dispatcher->dispatch(new ListenerHaltEvent('probe'));

    expect($log->entries)->toBe(['bean-produced']);
});

it('never registers the SAME class\'s listeners twice, even when reachable both as a definition\'s own class AND as another definition\'s #[Bean] return type', function () {
    $context = listenerContext();
    $log = new ListenerPassLog;
    $context->container->instance(ListenerPassLog::class, $log);

    // BeanProducedListener reachable as a plain #[Component] itself...
    $context->definitions->add(new BeanDefinition(listenerDescriptor(BeanProducedListener::class)));

    // ...AND (contrived, but must not double-fire) as a #[Bean] return type of a second definition.
    $configDescriptor = new ComponentDescriptor(
        class: 'App\BeanFactoryConfig',
        stereotype: 'Configuration',
        name: null,
        scope: Scope::Singleton,
        primary: false,
        order: 0,
        qualifier: null,
        interfaces: [],
        beans: [
            new BeanDescriptor(
                method: 'redisCache',
                returns: BeanProducedListener::class,
                name: null,
                scope: Scope::Singleton,
                primary: false,
                order: 0,
            ),
        ],
    );
    $context->definitions->add(new BeanDefinition($configDescriptor));

    (new RegisterEventListenersPass)->run($context);

    /** @var Dispatcher $dispatcher */
    $dispatcher = $context->container->make('events');
    $dispatcher->dispatch(new ListenerHaltEvent('probe'));

    expect($log->entries)->toBe(['bean-produced']);
});

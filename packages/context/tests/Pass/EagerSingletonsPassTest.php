<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Container\Attributes\Lazy;
use Firefly\Container\Descriptor\BeanDescriptor;
use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scope;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinition;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Firefly\Context\Event\AsEventListener;
use Firefly\Context\Event\DispatcherEventPublisher;
use Firefly\Context\Lifecycle\PostConstruct;
use Firefly\Context\Pass\EagerSingletonsPass;
use Firefly\Context\Pass\RegisterBeanPostProcessorsPass;
use Firefly\Context\Pass\RegisterEventListenersPass;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher as IlluminateDispatcher;

/**
 * EagerSingletonsPass is exercised through REAL fixture classes resolved by a REAL
 * Illuminate\Container\Container — never mocks of our own interfaces.
 */
final class EagerLog
{
    /** @var list<string> */
    public array $entries = [];

    public function record(string $entry): void
    {
        $this->entries[] = $entry;
    }
}

final class EagerWidgetA
{
    public function __construct(EagerLog $log)
    {
        $log->record('A');
    }
}

final class EagerWidgetB
{
    public function __construct(EagerLog $log)
    {
        $log->record('B');
    }
}

#[Lazy]
final class LazyEagerWidget
{
    public function __construct(EagerLog $log)
    {
        $log->record('lazy');
    }
}

final class EagerPublishedEvent
{
    public function __construct(public string $from) {}
}

final class EagerListenerReceiver
{
    public bool $received = false;

    #[AsEventListener]
    public function onPublished(EagerPublishedEvent $event): void
    {
        $this->received = true;
    }
}

final class PublishingEagerWidget
{
    public function __construct(private readonly DispatcherEventPublisher $publisher) {}

    #[PostConstruct]
    public function announce(): void
    {
        $this->publisher->publish(new EagerPublishedEvent('postConstruct'));
    }
}

/**
 * @param  list<BeanDescriptor>  $beans
 */
function eagerDescriptor(string $class, int $order = 0, Scope $scope = Scope::Singleton, array $beans = []): ComponentDescriptor
{
    return new ComponentDescriptor(
        class: $class,
        stereotype: 'Service',
        name: null,
        scope: $scope,
        primary: false,
        order: $order,
        qualifier: null,
        interfaces: [],
        beans: $beans,
    );
}

function eagerContext(): BootContext
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
    );
}

it('resolves non-#[Lazy] Scope::Singleton components in #[Order] from the manifest, skipping #[Lazy] ones', function () {
    $context = eagerContext();
    $log = new EagerLog;
    $context->container->instance(EagerLog::class, $log);

    // Registered out of order deliberately.
    $context->definitions->add(new BeanDefinition(eagerDescriptor(EagerWidgetB::class, order: 5)));
    $context->definitions->add(new BeanDefinition(eagerDescriptor(EagerWidgetA::class, order: 1)));
    $context->definitions->add(new BeanDefinition(eagerDescriptor(LazyEagerWidget::class, order: 0)));

    (new EagerSingletonsPass)->run($context);

    expect($log->entries)->toBe(['A', 'B']);
});

it('never eagerly resolves a Scope::Transient or Scope::Scoped component', function () {
    $context = eagerContext();
    $log = new EagerLog;
    $context->container->instance(EagerLog::class, $log);

    $context->definitions->add(new BeanDefinition(eagerDescriptor(EagerWidgetA::class, scope: Scope::Transient)));

    (new EagerSingletonsPass)->run($context);

    expect($log->entries)->toBe([]);
});

it('an event published from a #[PostConstruct] during eager resolution IS received — proves 800-before-900 ordering', function () {
    $context = eagerContext();
    $context->container->instance(DispatcherEventPublisher::class, new DispatcherEventPublisher($context->container));

    $receiver = new EagerListenerReceiver;
    $context->container->instance(EagerListenerReceiver::class, $receiver);

    $context->definitions->add(new BeanDefinition(eagerDescriptor(EagerListenerReceiver::class)));
    $context->definitions->add(new BeanDefinition(eagerDescriptor(PublishingEagerWidget::class)));

    // Phase order matters: BeanPostProcessors (700) installs the extender that fires
    // #[PostConstruct]; EventListeners (800) registers the listener; EagerSingletons (900) then
    // resolves PublishingEagerWidget, firing its #[PostConstruct] — which publishes an event that
    // must already have somewhere to go.
    (new RegisterBeanPostProcessorsPass)->run($context);
    (new RegisterEventListenersPass)->run($context);
    (new EagerSingletonsPass)->run($context);

    expect($receiver->received)->toBeTrue();
});

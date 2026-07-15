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
use Firefly\Context\Lifecycle\DisposableBeanRegistry;
use Firefly\Context\Lifecycle\PostConstruct;
use Firefly\Context\Lifecycle\PreDestroy;
use Firefly\Context\Pass\RegisterBeanPostProcessorsPass;
use Firefly\Context\Processor\BeanPostProcessor;
use Firefly\Context\Scanner\ContextDescriptor;
use Firefly\Context\Scanner\ContextManifest;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

/**
 * RegisterBeanPostProcessorsPass is exercised through REAL BeanPostProcessor implementations
 * resolved by a REAL Illuminate\Container\Container — never mocks of our own interfaces.
 */
final class BppPassLog
{
    /** @var list<string> */
    public array $entries = [];

    public function record(string $entry): void
    {
        $this->entries[] = $entry;
    }
}

final class LowOrderBpp implements BeanPostProcessor
{
    public function __construct(private readonly BppPassLog $log) {}

    public function beforeInitialization(object $bean, string $declaredClass): object
    {
        $this->log->record('before(low)');

        return $bean;
    }

    public function afterInitialization(object $bean, string $declaredClass): object
    {
        $this->log->record('after(low)');

        return $bean;
    }
}

final class HighOrderBpp implements BeanPostProcessor
{
    public function __construct(private readonly BppPassLog $log) {}

    public function beforeInitialization(object $bean, string $declaredClass): object
    {
        $this->log->record('before(high)');

        return $bean;
    }

    public function afterInitialization(object $bean, string $declaredClass): object
    {
        $this->log->record('after(high)');

        return $bean;
    }
}

/**
 * Simulates a proxy wrapping a resolved BPP: a DIFFERENT runtime class, absent from the manifest.
 * Firefly\Container\Container::orderOf() would silently sort this at order 0 if ordering were ever
 * derived from a resolved instance instead of the manifest (invariant 3).
 */
final class BppProxy implements BeanPostProcessor
{
    public function __construct(private readonly BeanPostProcessor $inner) {}

    public function beforeInitialization(object $bean, string $declaredClass): object
    {
        return $this->inner->beforeInitialization($bean, $declaredClass);
    }

    public function afterInitialization(object $bean, string $declaredClass): object
    {
        return $this->inner->afterInitialization($bean, $declaredClass);
    }
}

final class TargetWidget
{
    /** @var list<string> */
    public array $calls = [];

    #[PostConstruct]
    public function init(): void
    {
        $this->calls[] = 'construct';
    }
}

final class DisposableBppWidget
{
    public function __construct(private readonly BppPassLog $log) {}

    #[PreDestroy]
    public function shutdown(): void
    {
        $this->log->record('destroyed');
    }
}

/**
 * The canonical hexagonal shape this whole framework is built around: a #[Bean] method whose
 * RETURN TYPE is an interface. Structurally IDENTICAL to BppLifecycleConcrete below — same
 * #[PostConstruct]/#[PreDestroy] method names, same body shape — so the interface-vs-concrete
 * return type is the ONLY variable between the two fixtures (Critical 2's discriminating control).
 */
interface BppLifecyclePort {}

final class BppLifecycleImpl implements BppLifecyclePort
{
    public function __construct(private readonly BppPassLog $log) {}

    #[PostConstruct]
    public function connect(): void
    {
        $this->log->record('interface:connect');
    }

    #[PreDestroy]
    public function disconnect(): void
    {
        $this->log->record('interface:disconnect');
    }
}

/** The control: identical shape to BppLifecycleImpl, except its #[Bean] method returns ITSELF (a concrete class), not an interface. */
final class BppLifecycleConcrete
{
    public function __construct(private readonly BppPassLog $log) {}

    #[PostConstruct]
    public function connect(): void
    {
        $this->log->record('concrete:connect');
    }

    #[PreDestroy]
    public function disconnect(): void
    {
        $this->log->record('concrete:disconnect');
    }
}

/**
 * @param  list<class-string>  $interfaces
 * @param  list<BeanDescriptor>  $beans
 */
function bppDescriptor(string $class, int $order = 0, array $interfaces = [], array $beans = []): ComponentDescriptor
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
        beans: $beans,
    );
}

function bppBean(string $method, string $returns): BeanDescriptor
{
    return new BeanDescriptor(
        method: $method,
        returns: $returns,
        name: null,
        scope: Scope::Singleton,
        primary: false,
        order: 0,
    );
}

function bppContext(): BootContext
{
    $config = new Config(new Repository([]));
    $profiles = new Profiles([]);

    // Hand-built ContextManifest standing in for a real ContextScanner scan: TargetWidget and
    // DisposableBppWidget are declared inline in this test file (not under a scannable PSR-4
    // directory).
    $contextManifest = new ContextManifest([
        new ContextDescriptor(class: TargetWidget::class, postConstruct: ['init']),
        new ContextDescriptor(class: DisposableBppWidget::class, preDestroy: ['shutdown']),
    ]);

    return new BootContext(
        container: new Container,
        definitions: new BeanDefinitionRegistry,
        config: $config,
        profiles: $profiles,
        conditions: new ConditionEvaluator($config, $profiles),
        report: new ConditionEvaluationReport,
        contextManifest: $contextManifest,
    );
}

// --- INVARIANT 3 regression: order from the manifest, never from a resolved (proxied) instance ---

it('orders BPPs by #[Order] read from the manifest even when a resolved BPP instance is a proxy (invariant 3)', function () {
    $context = bppContext();
    $log = new BppPassLog;
    $context->container->instance(BppPassLog::class, $log);

    // HighOrderBpp's manifest order (10) is HIGHER than LowOrderBpp's (1) — it must run LAST.
    $context->definitions->add(new BeanDefinition(bppDescriptor(LowOrderBpp::class, order: 1, interfaces: [BeanPostProcessor::class])));
    $context->definitions->add(new BeanDefinition(bppDescriptor(HighOrderBpp::class, order: 10, interfaces: [BeanPostProcessor::class])));
    $context->definitions->add(new BeanDefinition(bppDescriptor(TargetWidget::class)));

    // Simulate HighOrderBpp's resolved instance being a PROXY: a different runtime class, absent
    // from the manifest. If ordering were derived from the resolved instance (e.g. via
    // Firefly\Container\Container::orderOf(), which keys on $instance::class) instead of the
    // manifest, this line alone would flip the expected order below.
    $context->container->extend(HighOrderBpp::class, function (object $o): object {
        /** @var BeanPostProcessor $o */
        return new BppProxy($o);
    });

    (new RegisterBeanPostProcessorsPass)->run($context);

    $context->container->make(TargetWidget::class);

    expect($log->entries)->toBe(['before(low)', 'before(high)', 'after(low)', 'after(high)']);
});

// --- exactly one extender per abstract ---

it('installs exactly ONE composite extender per abstract: two BPPs do not double-process a bean', function () {
    $context = bppContext();
    $log = new BppPassLog;
    $context->container->instance(BppPassLog::class, $log);

    $context->definitions->add(new BeanDefinition(bppDescriptor(LowOrderBpp::class, order: 1, interfaces: [BeanPostProcessor::class])));
    $context->definitions->add(new BeanDefinition(bppDescriptor(HighOrderBpp::class, order: 2, interfaces: [BeanPostProcessor::class])));
    $context->definitions->add(new BeanDefinition(bppDescriptor(TargetWidget::class)));

    (new RegisterBeanPostProcessorsPass)->run($context);

    /** @var TargetWidget $widget */
    $widget = $context->container->make(TargetWidget::class);

    expect($widget->calls)->toBe(['construct'])
        ->and($log->entries)->toBe(['before(low)', 'before(high)', 'after(low)', 'after(high)']);
});

// --- BPPs never post-process themselves into existence ---

it('excludes BPP classes themselves from the extended set', function () {
    $context = bppContext();
    $log = new BppPassLog;
    $context->container->instance(BppPassLog::class, $log);

    $context->definitions->add(new BeanDefinition(bppDescriptor(LowOrderBpp::class, order: 1, interfaces: [BeanPostProcessor::class])));

    (new RegisterBeanPostProcessorsPass)->run($context);

    $context->container->make(LowOrderBpp::class);

    expect($log->entries)->toBe([]);
});

// --- wires the shared DisposableBeanRegistry so #[PreDestroy] beans are tracked for context close ---

it('registers a resolved bean into the shared DisposableBeanRegistry for later #[PreDestroy] draining', function () {
    $context = bppContext();
    $log = new BppPassLog;
    $context->container->instance(BppPassLog::class, $log);

    $context->definitions->add(new BeanDefinition(bppDescriptor(DisposableBppWidget::class)));

    (new RegisterBeanPostProcessorsPass)->run($context);

    $context->container->make(DisposableBppWidget::class);

    /** @var DisposableBeanRegistry $registry */
    $registry = $context->container->make(DisposableBeanRegistry::class);
    $registry->drainSingletons();

    expect($log->entries)->toBe(['destroyed']);
});

// --- Critical 2 regression: a #[Bean] method returning an INTERFACE must keep its lifecycle ---
//
// The ONLY variable between BppLifecycleImpl and BppLifecycleConcrete is the #[Bean] method's
// return type (interface vs. concrete) — everything else (method names, bodies) is identical. This
// is the discriminating control the M4 review demanded: before the fix, the interface-returning
// bean silently lost BOTH #[PostConstruct] and #[PreDestroy] (RegisterBeanPostProcessorsPass used
// the #[Bean] method's DECLARED return type — the interface — as the lifecycle lookup key, but
// ContextScanner never scans interfaces, so the manifest has no entry for it and the lookup missed
// silently), while the concrete-returning control worked correctly throughout.

it('a #[Bean] method returning an INTERFACE still runs #[PostConstruct]/#[PreDestroy] — control: only the return type differs from the concrete case (Critical 2 regression)', function () {
    $config = new Config(new Repository([]));
    $profiles = new Profiles([]);
    $log = new BppPassLog;

    // Hand-built ContextManifest standing in for a real ContextScanner scan: ContextScanner never
    // scans an interface (ContextScanner::describe() returns null for one), so BOTH entries below
    // are correctly keyed by the CONCRETE implementation class — exactly what a real scan produces.
    $contextManifest = new ContextManifest([
        new ContextDescriptor(class: BppLifecycleImpl::class, postConstruct: ['connect'], preDestroy: ['disconnect']),
        new ContextDescriptor(class: BppLifecycleConcrete::class, postConstruct: ['connect'], preDestroy: ['disconnect']),
    ]);

    $context = new BootContext(
        container: new Container,
        definitions: new BeanDefinitionRegistry,
        config: $config,
        profiles: $profiles,
        conditions: new ConditionEvaluator($config, $profiles),
        report: new ConditionEvaluationReport,
        contextManifest: $contextManifest,
    );
    $context->container->instance(BppPassLog::class, $log);
    // The only place an interface abstract needs a manual binding: a real ContainerRegistrar would
    // have bound the #[Bean] factory method itself; this pass never resolves the bean, only extends
    // whatever abstract is requested.
    $context->container->bind(BppLifecyclePort::class, BppLifecycleImpl::class);

    $context->definitions->add(new BeanDefinition(bppDescriptor('App\LifecycleConfiguration', beans: [
        bppBean('redis', BppLifecyclePort::class),      // #[Bean] returning an INTERFACE
        bppBean('memory', BppLifecycleConcrete::class), // #[Bean] returning a CONCRETE class — the control
    ])));

    (new RegisterBeanPostProcessorsPass)->run($context);

    $context->container->make(BppLifecyclePort::class);
    $context->container->make(BppLifecycleConcrete::class);

    expect($log->entries)->toBe(['interface:connect', 'concrete:connect']);

    /** @var DisposableBeanRegistry $registry */
    $registry = $context->container->make(DisposableBeanRegistry::class);
    $registry->drainSingletons();

    expect($log->entries)->toBe([
        'interface:connect',
        'concrete:connect',
        'concrete:disconnect',
        'interface:disconnect',
    ]);
});

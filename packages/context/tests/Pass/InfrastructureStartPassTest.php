<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scope;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinition;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Firefly\Context\Lifecycle\LifecycleRegistry;
use Firefly\Context\Pass\InfrastructureStartPass;
use Firefly\Kernel\Lifecycle;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

/**
 * InfrastructureStartPass is exercised through REAL Firefly\Kernel\Lifecycle implementations
 * resolved by a REAL Illuminate\Container\Container — never mocks of our own interfaces.
 */
final class InfraStartLog
{
    /** @var list<string> */
    public array $entries = [];

    public function record(string $entry): void
    {
        $this->entries[] = $entry;
    }
}

final class FailingInfra implements Lifecycle
{
    public function start(): void
    {
        throw new RuntimeException('infra is down');
    }

    public function stop(): void {}
}

final class NotLifecycleWidget
{
    public function __construct(private readonly InfraStartLog $log)
    {
        $this->log->record('constructed:not-lifecycle');
    }
}

function infraDescriptor(string $class, int $order = 0, bool $lifecycle = true): ComponentDescriptor
{
    return new ComponentDescriptor(
        class: $class,
        stereotype: 'Service',
        name: null,
        scope: Scope::Singleton,
        primary: false,
        order: $order,
        qualifier: null,
        interfaces: $lifecycle ? [Lifecycle::class] : [],
        beans: [],
    );
}

function infraContext(): BootContext
{
    $config = new Config(new Repository([]));
    $profiles = new Profiles([]);

    return new BootContext(
        container: new Container,
        definitions: new BeanDefinitionRegistry,
        config: $config,
        profiles: $profiles,
        conditions: new ConditionEvaluator($config, $profiles),
        report: new ConditionEvaluationReport,
    );
}

it('starts Lifecycle components in #[Order] read from the manifest, regardless of registration order', function () {
    $context = infraContext();
    $log = new InfraStartLog;
    $context->container->instance(InfraStartLog::class, $log);

    // Two distinct Lifecycle classes so each can carry its own manifest order, registered in
    // reverse of the intended start order.
    $context->definitions->add(new BeanDefinition(infraDescriptor(HighOrderInfra::class, order: 10)));
    $context->definitions->add(new BeanDefinition(infraDescriptor(LowOrderInfra::class, order: 1)));

    $context->container->instance(HighOrderInfra::class, new HighOrderInfra($log));
    $context->container->instance(LowOrderInfra::class, new LowOrderInfra($log));

    (new InfrastructureStartPass)->run($context);

    expect($log->entries)->toBe(['start:low', 'start:high']);
});

it('propagates a failing start() (fail-fast): infrastructure that cannot start aborts boot loudly', function () {
    $context = infraContext();
    $log = new InfraStartLog;
    $context->container->instance(InfraStartLog::class, $log);

    $context->definitions->add(new BeanDefinition(infraDescriptor(FailingInfra::class)));

    expect(fn () => (new InfrastructureStartPass)->run($context))
        ->toThrow(RuntimeException::class, 'infra is down');
});

it('never resolves a component that does not implement Lifecycle', function () {
    $context = infraContext();
    $log = new InfraStartLog;
    $context->container->instance(InfraStartLog::class, $log);

    $context->definitions->add(new BeanDefinition(infraDescriptor(NotLifecycleWidget::class, lifecycle: false)));

    (new InfrastructureStartPass)->run($context);

    expect($log->entries)->toBe([]);
});

it('tracks each started component in the shared LifecycleRegistry, in start order', function () {
    $context = infraContext();
    $log = new InfraStartLog;
    $context->container->instance(InfraStartLog::class, $log);

    $context->definitions->add(new BeanDefinition(infraDescriptor(HighOrderInfra::class, order: 10)));
    $context->definitions->add(new BeanDefinition(infraDescriptor(LowOrderInfra::class, order: 1)));

    $context->container->instance(HighOrderInfra::class, new HighOrderInfra($log));
    $context->container->instance(LowOrderInfra::class, new LowOrderInfra($log));

    (new InfrastructureStartPass)->run($context);

    /** @var LifecycleRegistry $registry */
    $registry = $context->container->make(LifecycleRegistry::class);
    $registry->stopAll();

    // stopAll() reverses START order — the low-order component started FIRST, so it stops LAST.
    expect($log->entries)->toBe(['start:low', 'start:high', 'stop:high', 'stop:low']);
});

final class LowOrderInfra implements Lifecycle
{
    public function __construct(private readonly InfraStartLog $log) {}

    public function start(): void
    {
        $this->log->record('start:low');
    }

    public function stop(): void
    {
        $this->log->record('stop:low');
    }
}

final class HighOrderInfra implements Lifecycle
{
    public function __construct(private readonly InfraStartLog $log) {}

    public function start(): void
    {
        $this->log->record('start:high');
    }

    public function stop(): void
    {
        $this->log->record('stop:high');
    }
}

<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Firefly\Context\Event\ApplicationReadyEvent;
use Firefly\Context\Event\ContextRefreshedEvent;
use Firefly\Context\Pass\ContextRefreshedPass;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Events\Dispatcher as IlluminateDispatcher;

/**
 * ContextRefreshedPass is exercised against a REAL Illuminate\Events\Dispatcher — never a mock of
 * our own ApplicationEventPublisher port.
 */
final class RefreshLog
{
    /** @var list<string> */
    public array $entries = [];

    public function record(string $entry): void
    {
        $this->entries[] = $entry;
    }
}

function refreshContext(): BootContext
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

it('fires ContextRefreshedEvent then ApplicationReadyEvent, in that order', function () {
    $context = refreshContext();
    $log = new RefreshLog;

    /** @var Dispatcher $dispatcher */
    $dispatcher = $context->container->make('events');
    $dispatcher->listen(ContextRefreshedEvent::class, function () use ($log): void {
        $log->record('refreshed');
    });
    $dispatcher->listen(ApplicationReadyEvent::class, function () use ($log): void {
        $log->record('ready');
    });

    (new ContextRefreshedPass)->run($context);

    expect($log->entries)->toBe(['refreshed', 'ready']);
});

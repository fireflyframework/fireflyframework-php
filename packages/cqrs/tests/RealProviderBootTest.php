<?php

declare(strict_types=1);

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Cqrs\Command\CommandBus;
use Firefly\Cqrs\Command\DefaultCommandBus;
use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Cqrs\Event\CommandEventPublisher;
use Firefly\Cqrs\Event\EdaCommandEventPublisher;
use Firefly\Cqrs\Event\NoOpEventPublisher;
use Firefly\Cqrs\Query\DefaultQueryBus;
use Firefly\Cqrs\Query\QueryBus;
use Firefly\Eda\EventPublisher;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

function bootCqrsApp(bool $withEventPublisher): Application
{
    $app = new Application;
    $app->instance('config', new Repository(['firefly' => ['cqrs' => []]]));

    if ($withEventPublisher) {
        $app->instance(EventPublisher::class, fakeEventPublisher()); // helper shared from T11
    }

    $app->register(new FireflyAutoConfigureServiceProvider($app));
    $app->register(new CqrsServiceProvider($app));
    $app->register(new CqrsWiringProvider($app));
    $app->boot();

    return $app;
}

it('binds a working CommandBus + QueryBus + a NoOp bridge when cqrs boots alone', function () {
    /** @var ApplicationContext $context */
    $context = bootCqrsApp(false)->make(ApplicationContext::class);

    expect($context->get(CommandBus::class))->toBeInstanceOf(DefaultCommandBus::class)
        ->and($context->get(QueryBus::class))->toBeInstanceOf(DefaultQueryBus::class)
        ->and($context->get(CommandEventPublisher::class))->toBeInstanceOf(NoOpEventPublisher::class);
});

it('wires the Eda-backed bridge when an EventPublisher is bound (cqrs + eda together)', function () {
    /** @var ApplicationContext $context */
    $context = bootCqrsApp(true)->make(ApplicationContext::class);

    expect($context->get(CommandBus::class))->toBeInstanceOf(DefaultCommandBus::class)
        ->and($context->get(CommandEventPublisher::class))->toBeInstanceOf(EdaCommandEventPublisher::class);
});

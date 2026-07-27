<?php

declare(strict_types=1);

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

function bootCqrsApp(bool $withEventPublisher): ApplicationContext
{
    return bootFireflyApp(
        ['firefly' => ['cqrs' => []]],
        [CqrsServiceProvider::class, CqrsWiringProvider::class],
        bindings: $withEventPublisher ? [EventPublisher::class => fakeEventPublisher()] : [], // helper shared from T11
    );
}

it('binds a working CommandBus + QueryBus + a NoOp bridge when cqrs boots alone', function () {
    $context = bootCqrsApp(false);

    expect($context->get(CommandBus::class))->toBeInstanceOf(DefaultCommandBus::class)
        ->and($context->get(QueryBus::class))->toBeInstanceOf(DefaultQueryBus::class)
        ->and($context->get(CommandEventPublisher::class))->toBeInstanceOf(NoOpEventPublisher::class);
});

it('wires the Eda-backed bridge when an EventPublisher is bound (cqrs + eda together)', function () {
    $context = bootCqrsApp(true);

    expect($context->get(CommandBus::class))->toBeInstanceOf(DefaultCommandBus::class)
        ->and($context->get(CommandEventPublisher::class))->toBeInstanceOf(EdaCommandEventPublisher::class);
});

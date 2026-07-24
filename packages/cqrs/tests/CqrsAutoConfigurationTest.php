<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Cqrs\Command\CommandBus;
use Firefly\Cqrs\Command\DefaultCommandBus;
use Firefly\Cqrs\CqrsAutoConfiguration;
use Firefly\Cqrs\Event\CommandEventPublisher;
use Firefly\Cqrs\Event\EdaCommandEventPublisher;
use Firefly\Cqrs\Event\NoOpEventPublisher;
use Firefly\Cqrs\Handler\HandlerManifest;
use Firefly\Cqrs\Handler\HandlerRegistry;
use Firefly\Eda\EventPublisher;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

/**
 * @param  array<string, mixed>  $cqrs
 */
function cqrsConfig(array $cqrs = []): Config
{
    return new Config(new Repository(['firefly' => ['cqrs' => $cqrs]]));
}

it('is a #[Configuration] ordered 1000 whose beans are all #[ConditionalOnMissingBean]', function () {
    $class = new ReflectionClass(CqrsAutoConfiguration::class);

    expect($class->getAttributes(Configuration::class))->not->toBe([])
        ->and($class->getAttributes(Order::class)[0]->newInstance()->order)->toBe(1000)
        ->and($class->getMethod('commandBus')->getAttributes(ConditionalOnMissingBean::class)[0]->newInstance()->type)->toBe(CommandBus::class)
        ->and($class->getMethod('commandEventPublisher')->getAttributes(ConditionalOnMissingBean::class)[0]->newInstance()->type)->toBe(CommandEventPublisher::class);
});

it('builds a DefaultCommandBus and a NoOp bridge when no EventPublisher is bound', function () {
    $auto = new CqrsAutoConfiguration;
    $container = new Container;
    $manifest = new HandlerManifest([], []);

    $registry = $auto->handlerRegistry();
    $bus = $auto->commandBus($registry, $auto->messageValidator($container), $auto->commandAuthorizer(), $auto->correlationContext(), $auto->cqrsMetrics());

    expect($bus)->toBeInstanceOf(DefaultCommandBus::class)
        ->and($registry)->toBeInstanceOf(HandlerRegistry::class)
        ->and($auto->commandEventPublisher($container, cqrsConfig(), $manifest, $auto->correlationContext()))
        ->toBeInstanceOf(NoOpEventPublisher::class);
});

it('builds an EdaCommandEventPublisher when an EventPublisher IS bound', function () {
    $auto = new CqrsAutoConfiguration;
    $container = new Container;
    $container->instance(EventPublisher::class, fakeEventPublisher()); // helper shared from T11

    $bridge = $auto->commandEventPublisher($container, cqrsConfig(['default_destination' => 'x.events']), new HandlerManifest([], []), $auto->correlationContext());

    expect($bridge)->toBeInstanceOf(EdaCommandEventPublisher::class);
});

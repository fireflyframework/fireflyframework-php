<?php

declare(strict_types=1);

use Firefly\Cqrs\Exception\CommandHandlerNotFoundException;
use Firefly\Cqrs\Exception\CqrsConfigurationException;
use Firefly\Cqrs\Exception\QueryHandlerNotFoundException;
use Firefly\Cqrs\Handler\HandlerRegistry;

it('registers and finds command + query invokers independently', function () {
    $registry = new HandlerRegistry;
    $registry->registerCommandHandler('App\CreateOrder', fn (object $c): string => 'cmd:'.$c::class);
    $registry->registerQueryHandler('App\FindOrder', fn (object $q): string => 'qry:'.$q::class);

    $command = new stdClass;
    $query = new stdClass;

    expect($registry->hasCommandHandler('App\CreateOrder'))->toBeTrue()
        ->and($registry->hasQueryHandler('App\FindOrder'))->toBeTrue()
        ->and($registry->hasCommandHandler('App\FindOrder'))->toBeFalse()
        ->and(($registry->findCommandHandler('App\CreateOrder'))($command))->toBe('cmd:stdClass')
        ->and(($registry->findQueryHandler('App\FindOrder'))($query))->toBe('qry:stdClass');
});

it('lets the same message class key a command AND a query handler (separate maps)', function () {
    $registry = new HandlerRegistry;
    $registry->registerCommandHandler('App\Thing', fn (object $m): string => 'c');
    $registry->registerQueryHandler('App\Thing', fn (object $m): string => 'q'); // no clash across kinds

    expect(($registry->findCommandHandler('App\Thing'))(new stdClass))->toBe('c')
        ->and(($registry->findQueryHandler('App\Thing'))(new stdClass))->toBe('q');
});

it('fails loud on a duplicate command handler for the same message class', function () {
    $registry = new HandlerRegistry;
    $registry->registerCommandHandler('App\CreateOrder', fn (object $c): string => 'a');
    $registry->registerCommandHandler('App\CreateOrder', fn (object $c): string => 'b');
})->throws(CqrsConfigurationException::class, 'App\CreateOrder');

it('fails loud on a duplicate query handler for the same message class', function () {
    $registry = new HandlerRegistry;
    $registry->registerQueryHandler('App\FindOrder', fn (object $q): string => 'a');
    $registry->registerQueryHandler('App\FindOrder', fn (object $q): string => 'b');
})->throws(CqrsConfigurationException::class);

it('throws a typed not-found when a command handler is missing', function () {
    (new HandlerRegistry)->findCommandHandler('App\Unmapped');
})->throws(CommandHandlerNotFoundException::class, 'App\Unmapped');

it('throws a typed not-found when a query handler is missing', function () {
    (new HandlerRegistry)->findQueryHandler('App\Unmapped');
})->throws(QueryHandlerNotFoundException::class);

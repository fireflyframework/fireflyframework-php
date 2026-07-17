<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Business\BusinessException;
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;
use Firefly\Web\Exception\ExceptionHandlerDescriptor;
use Firefly\Web\Exception\ExceptionHandlerRegistry;

it('resolves the most-specific handler by hierarchy', function () {
    $registry = new ExceptionHandlerRegistry([
        new ExceptionHandlerDescriptor(BusinessException::class, 'A', 'onBusiness', true),
        new ExceptionHandlerDescriptor(ResourceNotFoundException::class, 'A', 'onNotFound', true),
    ]);

    $handler = $registry->resolve(new ResourceNotFoundException, null);

    expect($handler?->methodName)->toBe('onNotFound');
});

it('prefers a controller-local handler over a global one', function () {
    $registry = new ExceptionHandlerRegistry([
        new ExceptionHandlerDescriptor(ResourceNotFoundException::class, 'GlobalAdvice', 'globalNotFound', true),
        new ExceptionHandlerDescriptor(BusinessException::class, 'AccountsController', 'localBusiness', false),
    ]);

    $handler = $registry->resolve(new ResourceNotFoundException, 'AccountsController');

    expect($handler?->methodName)->toBe('localBusiness')
        ->and($handler?->global)->toBeFalse();
});

it('returns null when no handler matches', function () {
    $registry = new ExceptionHandlerRegistry([
        new ExceptionHandlerDescriptor(ResourceNotFoundException::class, 'A', 'onNotFound', true),
    ]);

    expect($registry->resolve(new LogicException, null))->toBeNull();
});

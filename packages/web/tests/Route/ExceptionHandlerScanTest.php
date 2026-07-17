<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Business\BusinessException;
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;
use Firefly\Web\Exception\ExceptionHandlerDescriptor;
use Firefly\Web\Route\RouteScanner;
use Firefly\Web\Tests\Fixtures\AccountAdvice;
use Firefly\Web\Tests\Fixtures\AccountsController;

it('scans #[ControllerAdvice] classes for #[ExceptionHandler] methods as global handlers', function () {
    $psr4 = ['Firefly\\Web\\Tests\\Fixtures\\' => dirname(__DIR__).'/Fixtures'];

    $handlers = (new RouteScanner)->scanExceptionHandlers($psr4);
    $advice = collect($handlers)->firstWhere('handlerClass', AccountAdvice::class);

    if (! $advice instanceof ExceptionHandlerDescriptor) {
        throw new RuntimeException('AccountAdvice handler not found in scan.');
    }

    expect($advice->exceptionClass)->toBe(ResourceNotFoundException::class)
        ->and($advice->methodName)->toBe('notFound')
        ->and($advice->global)->toBeTrue();
});

it('scans #[RestController] classes for #[ExceptionHandler] methods as controller-local handlers', function () {
    $psr4 = ['Firefly\\Web\\Tests\\Fixtures\\' => dirname(__DIR__).'/Fixtures'];

    $handlers = (new RouteScanner)->scanExceptionHandlers($psr4);
    $local = collect($handlers)->firstWhere('handlerClass', AccountsController::class);

    if (! $local instanceof ExceptionHandlerDescriptor) {
        throw new RuntimeException('AccountsController handler not found in scan.');
    }

    expect($local->exceptionClass)->toBe(BusinessException::class)
        ->and($local->methodName)->toBe('onBusiness')
        ->and($local->global)->toBeFalse();
});

<?php

declare(strict_types=1);

use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Exception\FireflyException;

it('is a runtime exception carrying framework error metadata', function () {
    $previous = new RuntimeException('root cause');
    $e = new FireflyException(
        message: 'boom',
        errorCode: 'X_ERROR',
        httpStatus: 418,
        category: ErrorCategory::Infrastructure,
        severity: ErrorSeverity::Critical,
        previous: $previous,
    );

    expect($e)->toBeInstanceOf(RuntimeException::class)
        ->and($e->getMessage())->toBe('boom')
        ->and($e->errorCode())->toBe('X_ERROR')
        ->and($e->httpStatus())->toBe(418)
        ->and($e->category())->toBe(ErrorCategory::Infrastructure)
        ->and($e->severity())->toBe(ErrorSeverity::Critical)
        ->and($e->getPrevious())->toBe($previous);
});

it('defaults to a 500 internal error', function () {
    $e = new FireflyException('unexpected', 'INTERNAL_ERROR');

    expect($e->httpStatus())->toBe(500)
        ->and($e->category())->toBe(ErrorCategory::Internal)
        ->and($e->severity())->toBe(ErrorSeverity::Error);
});

it('carries RFC 9457 extension members and a title of its own', function () {
    $e = (new FireflyException('The plan does not include this.', 'EDITION_REQUIRED', 402))
        ->withExtensions(['field' => 'limit', 'edition' => 'team'])
        ->withTitle('Your plan does not include this');

    expect($e->extensions())->toBe(['field' => 'limit', 'edition' => 'team'])
        ->and($e->title())->toBe('Your plan does not include this');
});

it('has no extensions and no title unless given some', function () {
    $e = new FireflyException('unexpected', 'INTERNAL_ERROR');

    expect($e->extensions())->toBe([])
        ->and($e->title())->toBeNull();
});

it('accepts extensions and a title through the constructor as well', function () {
    $e = new FireflyException(
        message: 'Nope.',
        errorCode: 'X',
        httpStatus: 409,
        extensions: ['retryable' => true],
        title: 'That cannot be done yet',
    );

    expect($e->extensions())->toBe(['retryable' => true])
        ->and($e->title())->toBe('That cannot be done yet');
});

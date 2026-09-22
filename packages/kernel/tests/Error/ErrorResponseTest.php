<?php

declare(strict_types=1);

use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorResponse;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Error\FieldError;
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;
use Firefly\Kernel\Exception\Business\ValidationException;

it('serialises to a problem+json array omitting empty optionals', function () {
    $r = new ErrorResponse(
        status: 404,
        title: 'Not Found',
        code: 'RESOURCE_NOT_FOUND',
        category: ErrorCategory::Business,
        severity: ErrorSeverity::Warning,
    );

    expect($r->toArray())->toBe([
        'status' => 404,
        'title' => 'Not Found',
        'code' => 'RESOURCE_NOT_FOUND',
        'category' => 'business',
        'severity' => 'warning',
    ]);
});

it('includes optionals and field errors when present', function () {
    $r = new ErrorResponse(
        status: 422,
        title: 'Validation failed',
        code: 'VALIDATION_ERROR',
        category: ErrorCategory::Validation,
        severity: ErrorSeverity::Warning,
        detail: 'The request body is invalid.',
        type: 'https://errors.firefly.dev/validation',
        instance: '/orders',
        traceId: 'trace-123',
        errors: [new FieldError('email', 'is required')],
        timestamp: '2026-07-14T00:00:00+00:00',
    );

    expect($r->toArray())->toBe([
        'status' => 422,
        'title' => 'Validation failed',
        'code' => 'VALIDATION_ERROR',
        'category' => 'validation',
        'severity' => 'warning',
        'detail' => 'The request body is invalid.',
        'type' => 'https://errors.firefly.dev/validation',
        'instance' => '/orders',
        'traceId' => 'trace-123',
        'timestamp' => '2026-07-14T00:00:00+00:00',
        'errors' => [
            ['field' => 'email', 'message' => 'is required'],
        ],
    ]);
});

it('builds from a FireflyException', function () {
    $e = new ResourceNotFoundException('Order 42 not found');
    $r = ErrorResponse::fromException($e, instance: '/orders/42', traceId: 't-1');

    expect($r->status)->toBe(404)
        ->and($r->code)->toBe('RESOURCE_NOT_FOUND')
        ->and($r->category)->toBe(ErrorCategory::Business)
        ->and($r->detail)->toBe('Order 42 not found')
        ->and($r->instance)->toBe('/orders/42')
        ->and($r->traceId)->toBe('t-1')
        ->and($r->errors)->toBe([]);
});

it('carries field errors when built from a ValidationException', function () {
    $fields = [new FieldError('email', 'is required')];
    $e = new ValidationException('Validation failed', $fields);
    $r = ErrorResponse::fromException($e);

    expect($r->status)->toBe(422)
        ->and($r->errors)->toBe($fields);
});

it('spreads extension members after the standard ones and never lets them override a standard member', function () {
    $e = (new ResourceNotFoundException('Order 42 not found'))
        ->withExtensions(['field' => 'orderId', 'status' => 999, 'code' => 'SPOOFED', 'title' => 'spoofed']);

    $payload = ErrorResponse::fromException($e)->toArray();

    expect($payload['status'])->toBe(404)
        ->and($payload['code'])->toBe('RESOURCE_NOT_FOUND')
        ->and($payload['title'])->toBe('Not Found')
        ->and($payload['field'])->toBe('orderId');
});

it('uses the exception\'s own title when it has one, and the status phrase otherwise', function () {
    $titled = ErrorResponse::fromException((new ResourceNotFoundException('Order 42 not found'))->withTitle('No such order'));
    $plain = ErrorResponse::fromException(new ResourceNotFoundException('Order 42 not found'));

    expect($titled->title)->toBe('No such order')
        ->and($plain->title)->toBe('Not Found');
});

it('titles 402 and 405', function () {
    expect(ErrorResponse::titleFor(402))->toBe('Payment Required')
        ->and(ErrorResponse::titleFor(405))->toBe('Method Not Allowed');
});

it('keeps extension members out of the array when there are none', function () {
    $payload = ErrorResponse::fromException(new ResourceNotFoundException('Order 42 not found'))->toArray();

    expect(array_keys($payload))->toBe(['status', 'title', 'code', 'category', 'severity', 'detail']);
});

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
        correlationId: 'corr-123',
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
        // Beside the trace id, in the document, whatever order the constructor takes its arguments in.
        'correlationId' => 'corr-123',
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

it('carries an RFC 9457 `type` through the same seam as every other renderer-supplied member', function () {
    // The members only a renderer knows arrive as arguments here — `instance`, `traceId`, `timestamp`,
    // `correlationId` and §3.1.1's `type` — so there is ONE construction site for the published document.
    // A renderer that re-declared this value object to set a single field would have a default for every
    // other member, and the day a member is added to this class it would vanish from every document that
    // site builds, in silence, with nothing to fail.
    $typed = ErrorResponse::fromException(
        new ResourceNotFoundException('Order 42 not found'),
        instance: '/orders/42',
        type: 'https://api.example.test/problems/resource-not-found',
    )->toArray();

    $untyped = ErrorResponse::fromException(new ResourceNotFoundException('Order 42 not found'))->toArray();

    expect($typed['type'])->toBe('https://api.example.test/problems/resource-not-found')
        // The member sits where toArray() writes it — after `detail`, before `instance` — whatever order
        // the arguments arrived in, which is the reason the argument could be appended at all.
        ->and(array_keys($typed))->toBe(['status', 'title', 'code', 'category', 'severity', 'detail', 'type', 'instance'])
        // And nothing supplied means nothing published: the member stays optional, so a caller that never
        // names it still gets the pre-9457 document byte for byte.
        ->and($untyped)->not->toHaveKey('type');
});

it('omits correlationId when nothing supplied one, and never lets an extension forge it', function () {
    $plain = ErrorResponse::fromException(new ResourceNotFoundException('Order 42 not found'))->toArray();

    // An extension member of the same name is dropped like any other standard member's impostor: the id is
    // the request's, published by the web layer, not something a throw site can put in the document.
    $forged = ErrorResponse::fromException(
        (new ResourceNotFoundException('Order 42 not found'))->withExtensions(['correlationId' => 'spoofed']),
        correlationId: 'corr-42',
    )->toArray();

    expect($plain)->not->toHaveKey('correlationId')
        ->and($forged['correlationId'])->toBe('corr-42');
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

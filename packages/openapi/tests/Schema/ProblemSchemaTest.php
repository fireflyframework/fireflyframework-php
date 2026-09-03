<?php

declare(strict_types=1);

use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorResponse;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Error\FieldError;
use Firefly\OpenApi\Schema\ProblemSchema;

/**
 * The error component is only worth anything if it describes what the framework REALLY sends, so the tests
 * generate a real ErrorResponse and check the schema against its actual payload rather than against RFC 9457
 * in the abstract — the two differ, and the extension members are the half a client branches on.
 */
it('declares exactly the members ErrorResponse always emits as required', function () {
    $payload = new ErrorResponse(
        status: 422,
        title: 'Unprocessable Entity',
        code: 'VALIDATION_FAILED',
        category: ErrorCategory::Validation,
        severity: ErrorSeverity::Warning,
    )->toArray();

    /** @var list<string> $required */
    $required = ProblemSchema::schema()['required'];

    expect(array_keys($payload))->toBe($required);
});

it('describes every optional member ErrorResponse can add', function () {
    $payload = new ErrorResponse(
        status: 422,
        title: 'Unprocessable Entity',
        code: 'VALIDATION_FAILED',
        category: ErrorCategory::Validation,
        severity: ErrorSeverity::Warning,
        detail: 'The reference must not be blank.',
        type: 'https://example.test/problems/validation',
        instance: 'api/orders',
        traceId: 'abc123',
        errors: [new FieldError('reference', 'must not be blank', 'NotBlank', '')],
        timestamp: '2026-09-03T00:00:00+00:00',
    )->toArray();

    /** @var array<string, mixed> $properties */
    $properties = ProblemSchema::schema()['properties'];

    $undocumented = array_values(array_diff(array_keys($payload), array_keys($properties)));

    expect($undocumented)->toBe([], 'ErrorResponse emits members the problem schema does not describe');
});

it('mirrors FieldError::toArray() in the errors item schema', function () {
    $field = new FieldError('reference', 'must not be blank', 'NotBlank', 'x')->toArray();

    /** @var array<string, array<string, mixed>> $properties */
    $properties = ProblemSchema::schema()['properties'];
    /** @var array<string, mixed> $items */
    $items = $properties['errors']['items'];
    /** @var array<string, mixed> $itemProperties */
    $itemProperties = $items['properties'];

    expect(array_keys($itemProperties))->toBe(array_keys($field))
        ->and($items['required'])->toBe(['field', 'message']);
});

it('enumerates the category and severity cases straight off the kernel enums', function () {
    /** @var array<string, array<string, mixed>> $properties */
    $properties = ProblemSchema::schema()['properties'];

    expect($properties['category']['enum'])->toBe(array_map(static fn (ErrorCategory $c): string => $c->value, ErrorCategory::cases()))
        ->and($properties['severity']['enum'])->toBe(array_map(static fn (ErrorSeverity $s): string => $s->value, ErrorSeverity::cases()));
});

it('serves the shared response as problem+json pointing at the shared schema', function () {
    expect(ProblemSchema::response()['content'])->toBe([
        'application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/ProblemDetails']],
    ])
        ->and(ProblemSchema::REF)->toBe('#/components/schemas/ProblemDetails')
        ->and(ProblemSchema::RESPONSE_REF)->toBe('#/components/responses/Problem');
});

<?php

declare(strict_types=1);

use Firefly\Kernel\Error\FieldError;

it('holds field-level validation detail', function () {
    $fe = new FieldError('email', 'must be a valid email', 'email', 'not-an-email');

    expect($fe->field)->toBe('email')
        ->and($fe->message)->toBe('must be a valid email')
        ->and($fe->code)->toBe('email')
        ->and($fe->rejectedValue)->toBe('not-an-email');
});

it('serialises to an array omitting null optionals', function () {
    $fe = new FieldError('name', 'is required');

    expect($fe->toArray())->toBe([
        'field' => 'name',
        'message' => 'is required',
    ]);
});

it('includes optionals when present', function () {
    $fe = new FieldError('age', 'must be >= 0', 'min', -1);

    expect($fe->toArray())->toBe([
        'field' => 'age',
        'message' => 'must be >= 0',
        'code' => 'min',
        'rejectedValue' => -1,
    ]);
});

it('carries the constraint that failed and serialises it after code', function () {
    $fe = new FieldError('lines[1].sku', 'must match "^[A-Z0-9]+$"', rejectedValue: 'bad sku!', constraint: 'Pattern');

    expect($fe->constraint)->toBe('Pattern')
        ->and($fe->toArray())->toBe([
            'field' => 'lines[1].sku',
            'message' => 'must match "^[A-Z0-9]+$"',
            'constraint' => 'Pattern',
            'rejectedValue' => 'bad sku!',
        ]);
});

it('omits the constraint when nothing declared one', function () {
    expect((new FieldError('name', 'is required'))->toArray())->not->toHaveKey('constraint');
});

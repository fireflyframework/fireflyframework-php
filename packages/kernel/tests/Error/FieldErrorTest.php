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

<?php

declare(strict_types=1);

use Firefly\Validation\Constraint\AssertFalse;
use Firefly\Validation\Constraint\AssertTrue;
use Firefly\Validation\Constraint\Constraint;
use Firefly\Validation\Constraint\Digits;
use Firefly\Validation\Constraint\Email;
use Firefly\Validation\Constraint\Future;
use Firefly\Validation\Constraint\Past;
use Firefly\Validation\Constraint\Pattern;
use Firefly\Validation\Constraint\Rules;
use Firefly\Validation\Rule\Iban;

it('maps the batch-2 string constraints', function (Constraint $constraint, array $expected) {
    expect($constraint->toRules())->toBe($expected);
})->with([
    'Email' => [new Email, ['email']],
    'Pattern' => [new Pattern('/^[A-Z]+$/D'), ['regex:/^[A-Z]+$/D']],
    'Past' => [new Past, ['date', 'before:now']],
    'Future' => [new Future, ['date', 'after:now']],
    'AssertTrue' => [new AssertTrue, ['accepted']],
    'AssertFalse' => [new AssertFalse, ['declined']],
    'Digits fraction' => [new Digits(4, 2), ['numeric', 'regex:/^-?\d{1,4}(\.\d{1,2})?$/D']],
    'Digits integer only' => [new Digits(6), ['numeric', 'regex:/^-?\d{1,6}$/D']],
]);

it('Rules returns the given rule list verbatim, mixing strings and rule objects', function () {
    $rule = new Iban;
    $constraint = new Rules('required', 'string', $rule);

    expect($constraint->toRules())->toBe(['required', 'string', $rule]);
});

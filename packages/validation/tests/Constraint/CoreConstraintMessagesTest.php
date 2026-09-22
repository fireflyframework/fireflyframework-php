<?php

declare(strict_types=1);

use Firefly\Validation\Constraint\AssertFalse;
use Firefly\Validation\Constraint\AssertTrue;
use Firefly\Validation\Constraint\Constraint;
use Firefly\Validation\Constraint\Digits;
use Firefly\Validation\Constraint\Email;
use Firefly\Validation\Constraint\Future;
use Firefly\Validation\Constraint\HasMessage;
use Firefly\Validation\Constraint\Max;
use Firefly\Validation\Constraint\Min;
use Firefly\Validation\Constraint\Negative;
use Firefly\Validation\Constraint\NegativeOrZero;
use Firefly\Validation\Constraint\NotBlank;
use Firefly\Validation\Constraint\NotEmpty;
use Firefly\Validation\Constraint\NotNull as NotNullConstraint;
use Firefly\Validation\Constraint\Past;
use Firefly\Validation\Constraint\Pattern;
use Firefly\Validation\Constraint\Positive;
use Firefly\Validation\Constraint\PositiveOrZero;
use Firefly\Validation\Constraint\Rules;
use Firefly\Validation\Constraint\Size;
use Firefly\Validation\Rule\Size as SizeRule;

/**
 * The sentence each core constraint publishes when it fails — Spring's FieldError `defaultMessage`, which
 * describes the CONSTRAINT and never the attribute. Hibernate Validator's ValidationMessages.properties
 * where one exists; #[Size] with one bound gets the honest half-sentence Hibernate spells with
 * Integer.MAX_VALUE.
 */
it('publishes the constraint\'s own sentence, the way Spring\'s FieldError does', function (Constraint&HasMessage $constraint, ?string $sentence) {
    expect($constraint->message())->toBe($sentence);
})->with([
    'NotBlank' => [new NotBlank, 'must not be blank'],
    'NotEmpty' => [new NotEmpty, 'must not be empty'],
    'NotNull' => [new NotNullConstraint, 'must not be null'],
    'Size both bounds' => [new Size(min: 1, max: 50), 'size must be between 1 and 50'],
    'Size min only' => [new Size(min: 3), 'size must be at least 3'],
    'Size max only' => [new Size(max: 120), 'size must be at most 120'],
    'Size unbounded' => [new Size, null],
    'Min' => [new Min(5), 'must be greater than or equal to 5'],
    'Max float' => [new Max(2.5), 'must be less than or equal to 2.5'],
    'Positive' => [new Positive, 'must be greater than 0'],
    'PositiveOrZero' => [new PositiveOrZero, 'must be greater than or equal to 0'],
    'Negative' => [new Negative, 'must be less than 0'],
    'NegativeOrZero' => [new NegativeOrZero, 'must be less than or equal to 0'],
    'Digits' => [new Digits(integer: 3, fraction: 2), 'numeric value out of bounds (<3 digits>.<2 digits> expected)'],
    'Pattern' => [new Pattern('/^[A-Z0-9][A-Z0-9-]{2,31}$/D'), 'must match "^[A-Z0-9][A-Z0-9-]{2,31}$"'],
    'Pattern with brace delimiters' => [new Pattern('{^[a-z]+$}i'), 'must match "^[a-z]+$"'],
    'Email' => [new Email, 'must be a well-formed email address'],
    'Past' => [new Past, 'must be a past date'],
    'Future' => [new Future, 'must be a future date'],
    'AssertTrue' => [new AssertTrue, 'must be true'],
    'AssertFalse' => [new AssertFalse, 'must be false'],
    'Rules' => [new Rules('min:3'), null],
]);

it('lets the message element win, with Bean Validation\'s {placeholders} filled in', function () {
    expect((new NotBlank(message: 'give us a name'))->message())->toBe('give us a name')
        ->and((new NotBlank(message: 'give us a name'))->hasCustomMessage())->toBeTrue()
        ->and((new NotBlank)->hasCustomMessage())->toBeFalse()
        ->and((new Size(min: 1, max: 50, message: 'between {min} and {max} lines'))->message())->toBe('between 1 and 50 lines')
        ->and((new Min(18, message: 'at least {value}'))->message())->toBe('at least 18')
        ->and((new Pattern('/^[A-Z]+$/', message: 'upper case only ({regexp})'))->message())->toBe('upper case only (^[A-Z]+$)')
        ->and((new Digits(2, 1, message: '{integer}.{fraction}'))->message())->toBe('2.1');
});

it('reads the message element of #[Rules] out of the variadic without disturbing the rules', function () {
    $rules = new Rules('min:3', 'string', message: 'must be at least 3 characters');

    expect($rules->rules)->toBe(['min:3', 'string'])
        ->and($rules->message)->toBe('must be at least 3 characters')
        ->and($rules->message())->toBe('must be at least 3 characters')
        ->and($rules->hasCustomMessage())->toBeTrue()
        ->and((new Rules('min:3'))->message())->toBeNull()
        ->and((new Rules('min:3'))->hasCustomMessage())->toBeFalse();
});

it('keeps every rule list exactly as it was', function () {
    // The sentences are new; the compiled rules are not. CoreConstraintsBatchOneTest pins each list; this
    // pins that the message element is inert on the rule side.
    expect((new NotBlank(message: 'x'))->toRules())->toBe(['required', 'string', 'regex:/\S/'])
        ->and((new Positive(message: 'x'))->toRules())->toBe(['numeric', 'gt:0'])
        ->and((new Size(min: 2, max: 8, message: 'x'))->toRules()[0])->toBeInstanceOf(SizeRule::class);
});

it('strips PCRE delimiters and modifiers from a pattern, and leaves an undelimited expression alone', function () {
    expect(Pattern::bare('/^[A-Z]+$/D'))->toBe('^[A-Z]+$')
        ->and(Pattern::bare('~^\d{4}$~'))->toBe('^\d{4}$')
        ->and(Pattern::bare('(^a|b$)i'))->toBe('^a|b$')
        ->and(Pattern::bare('^not-delimited$'))->toBe('^not-delimited$');
});

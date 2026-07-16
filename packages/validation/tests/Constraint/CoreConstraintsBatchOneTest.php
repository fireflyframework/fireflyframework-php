<?php

declare(strict_types=1);

use Firefly\Validation\Constraint\Constraint;
use Firefly\Validation\Constraint\Max;
use Firefly\Validation\Constraint\Min;
use Firefly\Validation\Constraint\Negative;
use Firefly\Validation\Constraint\NegativeOrZero;
use Firefly\Validation\Constraint\NotBlank;
use Firefly\Validation\Constraint\NotEmpty;
use Firefly\Validation\Constraint\NotNull as NotNullConstraint;
use Firefly\Validation\Constraint\Positive;
use Firefly\Validation\Constraint\PositiveOrZero;
use Firefly\Validation\Constraint\Size;
use Firefly\Validation\Rule\NotNull as NotNullRule;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Translation\Translator;

/** @return list<string> the failure messages the rule emitted (empty === passed) */
function runNotNullRule(NotNullRule $rule, mixed $value): array
{
    $messages = [];
    $translator = new Translator(new ArrayLoader, 'en');
    $rule->validate('field', $value, function (string $message) use (&$messages, $translator) {
        $messages[] = $message;

        return new class($message, $translator) extends PotentiallyTranslatedString {};
    });

    return $messages;
}

it('maps NotBlank / NotEmpty / NotNull to their string rules (plus the not-null rule object)', function () {
    expect((new NotBlank)->toRules())->toBe(['required', 'string', 'regex:/\S/'])
        ->and((new NotEmpty)->toRules())->toBe(['required']);

    $notNull = (new NotNullConstraint)->toRules();
    expect($notNull[0])->toBe('present')
        ->and($notNull[1])->toBeInstanceOf(NotNullRule::class);
});

it('the NotNull rule rejects only strict null, allowing empty string / 0 / false', function () {
    expect(runNotNullRule(new NotNullRule, null))->not->toBe([])
        ->and(runNotNullRule(new NotNullRule, ''))->toBe([])
        ->and(runNotNullRule(new NotNullRule, 0))->toBe([])
        ->and(runNotNullRule(new NotNullRule, false))->toBe([]);
});

it('maps Size to min/max/between', function () {
    expect((new Size(min: 2))->toRules())->toBe(['min:2'])
        ->and((new Size(max: 8))->toRules())->toBe(['max:8'])
        ->and((new Size(min: 2, max: 8))->toRules())->toBe(['between:2,8'])
        ->and((new Size)->toRules())->toBe([]);
});

it('maps the numeric bound constraints', function (Constraint $constraint, array $expected) {
    expect($constraint->toRules())->toBe($expected);
})->with([
    'Min' => [new Min(5), ['numeric', 'gte:5']],
    'Max float' => [new Max(2.5), ['numeric', 'lte:2.5']],
    'Positive' => [new Positive, ['numeric', 'gt:0']],
    'PositiveOrZero' => [new PositiveOrZero, ['numeric', 'gte:0']],
    'Negative' => [new Negative, ['numeric', 'lt:0']],
    'NegativeOrZero' => [new NegativeOrZero, ['numeric', 'lte:0']],
]);

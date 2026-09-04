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
use Firefly\Validation\Rule\Size as SizeRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Translation\Translator;

/** @return list<string> the failure messages the rule emitted (empty === passed) */
function runCoreRule(ValidationRule $rule, mixed $value): array
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
    expect(runCoreRule(new NotNullRule, null))->not->toBe([])
        ->and(runCoreRule(new NotNullRule, ''))->toBe([])
        ->and(runCoreRule(new NotNullRule, 0))->toBe([])
        ->and(runCoreRule(new NotNullRule, false))->toBe([]);
});

it('maps Size to a size-measuring rule object, and an unbounded Size to nothing', function () {
    // Deliberately NOT Laravel's min:/max:/between: strings any more: those read the property's OTHER rules
    // to decide whether they compare a length or a number. See SizeLengthSemanticsTest for the collision.
    $bounded = (new Size(min: 2, max: 8))->toRules();

    expect($bounded)->toHaveCount(1)
        ->and($bounded[0])->toBeInstanceOf(SizeRule::class)
        ->and($bounded[0]->min())->toBe(2)
        ->and($bounded[0]->max())->toBe(8)
        ->and((new Size(min: 2))->toRules()[0]->max())->toBeNull()
        ->and((new Size(max: 8))->toRules()[0]->min())->toBeNull()
        ->and((new Size)->toRules())->toBe([]);
});

it('the Size rule measures characters and elements, never magnitude', function () {
    expect(runCoreRule(new SizeRule(min: 3), '12'))->not->toBe([])
        ->and(runCoreRule(new SizeRule(min: 3), 12345))->toBe([])
        ->and(runCoreRule(new SizeRule(max: 2), [1, 2, 3]))->not->toBe([])
        ->and(runCoreRule(new SizeRule(max: 2), new ArrayObject([1, 2])))->toBe([])
        ->and(runCoreRule(new SizeRule(min: 2), 'ñá'))->toBe([])
        ->and(runCoreRule(new SizeRule(min: 1), null))->toBe([])
        ->and(runCoreRule(new SizeRule(min: 1), true))->not->toBe([]);
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

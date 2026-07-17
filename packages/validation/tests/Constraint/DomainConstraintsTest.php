<?php

declare(strict_types=1);

use Firefly\Validation\Constraint\Bic;
use Firefly\Validation\Constraint\Constraint;
use Firefly\Validation\Constraint\CountryCode;
use Firefly\Validation\Constraint\CurrencyCode;
use Firefly\Validation\Constraint\Cusip;
use Firefly\Validation\Constraint\DecimalScale as DecimalScaleConstraint;
use Firefly\Validation\Constraint\Iban as IbanConstraint;
use Firefly\Validation\Constraint\Isin;
use Firefly\Validation\Constraint\LanguageTag;
use Firefly\Validation\Constraint\Luhn;
use Firefly\Validation\Constraint\Money;
use Firefly\Validation\Constraint\Percentage as PercentageConstraint;
use Firefly\Validation\Constraint\Phone;
use Firefly\Validation\Constraint\PostalCode as PostalCodeConstraint;
use Firefly\Validation\Constraint\RoutingNumber;
use Firefly\Validation\Constraint\Swift;
use Firefly\Validation\Constraint\UuidValue;
use Firefly\Validation\Rule\Currency;
use Firefly\Validation\Rule\DecimalScale;
use Firefly\Validation\Rule\E164;
use Firefly\Validation\Rule\Iban;
use Firefly\Validation\Rule\Percentage;
use Firefly\Validation\Rule\PositiveMoney;
use Firefly\Validation\Rule\PostalCode;
use Firefly\Validation\Rule\Uuid;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Translation\Translator;

/** @return list<string> the failure messages the rule emitted (empty === passed) */
function runDomainRule(ValidationRule $rule, mixed $value): array
{
    $messages = [];
    $translator = new Translator(new ArrayLoader, 'en');
    $rule->validate('field', $value, function (string $message) use (&$messages, $translator) {
        $messages[] = $message;

        return new class($message, $translator) extends PotentiallyTranslatedString {};
    });

    return $messages;
}

it('wraps the shipped Rule object for each domain constraint', function (Constraint $constraint, string $ruleClass) {
    $rules = $constraint->toRules();
    expect($rules)->toHaveCount(1);

    $rule = $rules[0];
    if (! is_object($rule)) {
        throw new RuntimeException('expected the domain constraint to wrap a rule object');
    }

    expect($rule::class)->toBe($ruleClass);
})->with([
    'Iban' => [new IbanConstraint, Iban::class],
    'Bic' => [new Bic, Firefly\Validation\Rule\Bic::class],
    'Swift' => [new Swift, Firefly\Validation\Rule\Swift::class],
    'Isin' => [new Isin, Firefly\Validation\Rule\Isin::class],
    'Cusip' => [new Cusip, Firefly\Validation\Rule\Cusip::class],
    'RoutingNumber' => [new RoutingNumber, Firefly\Validation\Rule\RoutingNumber::class],
    'Luhn' => [new Luhn, Firefly\Validation\Rule\Luhn::class],
    'CurrencyCode' => [new CurrencyCode, Currency::class],
    'CountryCode' => [new CountryCode, Firefly\Validation\Rule\CountryCode::class],
    'LanguageTag' => [new LanguageTag, Firefly\Validation\Rule\LanguageTag::class],
    'UuidValue' => [new UuidValue, Uuid::class],
    'Phone' => [new Phone, E164::class],
    'PostalCode' => [new PostalCodeConstraint, PostalCode::class],
    'Percentage' => [new PercentageConstraint, Percentage::class],
    'Money' => [new Money, PositiveMoney::class],
]);

it('DecimalScale passes its scale through to the wrapped rule', function () {
    $rules = (new DecimalScaleConstraint(2))->toRules();

    expect($rules[0])->toBeInstanceOf(DecimalScale::class)
        ->and(runDomainRule($rules[0], '3.14'))->toBe([])
        ->and(runDomainRule($rules[0], '3.141'))->not->toBe([]);
});

it('the wrapped regex-backed rules reject a trailing newline (M5 /D discipline carries)', function (Constraint $constraint, mixed $newlineValue) {
    $rule = $constraint->toRules()[0];

    if (! $rule instanceof ValidationRule) {
        throw new RuntimeException('expected the domain constraint to wrap a ValidationRule instance');
    }

    expect(runDomainRule($rule, $newlineValue))->not->toBe([]);
})->with([
    'Iban newline' => [new IbanConstraint, "GB82WEST12345698765432\n"],
    'CurrencyCode newline' => [new CurrencyCode, "EUR\n"],
    'CountryCode newline' => [new CountryCode, "ES\n"],
    'LanguageTag newline' => [new LanguageTag, "en-GB\n"],
    'UuidValue newline' => [new UuidValue, "9b2e4f7a-3c1d-4e5f-8a6b-0c1d2e3f4a5b\n"],
    'Phone newline' => [new Phone, "+14155552671\n"],
    'PostalCode newline' => [new PostalCodeConstraint, "28013\n"],
    'Percentage newline' => [new PercentageConstraint, "50\n"],
    'Money newline' => [new Money, "19.99\n"],
]);

<?php

declare(strict_types=1);

use Firefly\Validation\Rule\CountryCode;
use Firefly\Validation\Rule\Currency;
use Firefly\Validation\Rule\DecimalScale;
use Firefly\Validation\Rule\E164;
use Firefly\Validation\Rule\LanguageTag;
use Firefly\Validation\Rule\Percentage;
use Firefly\Validation\Rule\PositiveMoney;
use Firefly\Validation\Rule\PostalCode;
use Firefly\Validation\Rule\Uuid;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Translation\Translator;

/** @return list<string> the failure messages the rule emitted (empty === passed) */
function runFormatRule(ValidationRule $rule, mixed $value): array
{
    $messages = [];
    $translator = new Translator(new ArrayLoader, 'en');
    $rule->validate('field', $value, function (string $message) use (&$messages, $translator) {
        $messages[] = $message;

        return new class($message, $translator) extends PotentiallyTranslatedString {};
    });

    return $messages;
}

it('accepts valid values', function (ValidationRule $rule, mixed $value) {
    expect(runFormatRule($rule, $value))->toBe([]);
})->with([
    'currency EUR' => [new Currency, 'EUR'],
    'country ES' => [new CountryCode, 'ES'],
    'lang en-GB' => [new LanguageTag, 'en-GB'],
    'uuid v4' => [new Uuid, '9b2e4f7a-3c1d-4e5f-8a6b-0c1d2e3f4a5b'],
    'e164' => [new E164, '+14155552671'],
    'postal' => [new PostalCode, '28013'],
    'percentage 50' => [new Percentage, 50],
    'percentage 0' => [new Percentage, 0],
    'money 19.99' => [new PositiveMoney, '19.99'],
    'scale 2 ok' => [new DecimalScale(2), '3.14'],
]);

it('rejects invalid values with a message', function (ValidationRule $rule, mixed $value) {
    expect(runFormatRule($rule, $value))->not->toBe([]);
})->with([
    'currency lower' => [new Currency, 'eur'],
    'currency fake' => [new Currency, 'ZZZ'],
    'country long' => [new CountryCode, 'ESP'],
    'lang bad' => [new LanguageTag, 'e'],
    'uuid bad' => [new Uuid, 'not-a-uuid'],
    'e164 no plus' => [new E164, '14155552671'],
    'postal bad' => [new PostalCode, '@@@'],
    'percentage 101' => [new Percentage, 101],
    'percentage neg' => [new Percentage, -1],
    'money zero' => [new PositiveMoney, '0'],
    'money 3dp' => [new PositiveMoney, '1.234'],
    'scale 2 over' => [new DecimalScale(2), '3.141'],
    // Trailing-newline REJECT rows: a bare `$` matches before a trailing "\n" (PCRE without /D) and
    // is_numeric("50\n") is true in PHP 8, so an otherwise-valid value with a trailing newline must
    // NOT be accepted — locks in the /D anchoring and the Percentage strict-decimal guard.
    'currency newline' => [new Currency, "EUR\n"],
    'country newline' => [new CountryCode, "ES\n"],
    'lang newline' => [new LanguageTag, "en-GB\n"],
    'uuid newline' => [new Uuid, "9b2e4f7a-3c1d-4e5f-8a6b-0c1d2e3f4a5b\n"],
    'e164 newline' => [new E164, "+14155552671\n"],
    'postal newline' => [new PostalCode, "28013\n"],
    'percentage newline' => [new Percentage, "50\n"],
    'money newline' => [new PositiveMoney, "19.99\n"],
    'scale newline' => [new DecimalScale(2), "3.14\n"],
]);

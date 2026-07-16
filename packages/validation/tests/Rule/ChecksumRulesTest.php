<?php

declare(strict_types=1);

use Firefly\Validation\Rule\Bic;
use Firefly\Validation\Rule\Cusip;
use Firefly\Validation\Rule\Iban;
use Firefly\Validation\Rule\Isin;
use Firefly\Validation\Rule\Luhn;
use Firefly\Validation\Rule\RoutingNumber;
use Firefly\Validation\Rule\Swift;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Translation\Translator;

/** @return list<string> the failure messages the rule emitted (empty === passed) */
function runRule(ValidationRule $rule, mixed $value): array
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
    expect(runRule($rule, $value))->toBe([]);
})->with([
    'luhn visa' => [new Luhn, '4111111111111111'],
    'iban DE' => [new Iban, 'DE89370400440532013000'],
    'iban GB' => [new Iban, 'GB82 WEST 1234 5698 7654 32'],
    'bic 8' => [new Bic, 'DEUTDEFF'],
    'bic 11' => [new Bic, 'DEUTDEFF500'],
    'swift 11' => [new Swift, 'NEDSZAJJXXX'],
    'isin apple' => [new Isin, 'US0378331005'],
    'cusip apple' => [new Cusip, '037833100'],
    'routing valid' => [new RoutingNumber, '021000021'],
]);

it('rejects invalid values with a message', function (ValidationRule $rule, mixed $value) {
    expect(runRule($rule, $value))->not->toBe([]);
})->with([
    'luhn bad' => [new Luhn, '4111111111111112'],
    'iban bad csum' => [new Iban, 'DE89370400440532013001'],
    'iban garbage' => [new Iban, 'not-an-iban'],
    'bic short' => [new Bic, 'ABC'],
    'swift short' => [new Swift, 'ABC'],
    'isin bad' => [new Isin, 'US0378331006'],
    'cusip bad' => [new Cusip, '037833101'],
    'routing bad' => [new RoutingNumber, '021000020'],
    'routing len' => [new RoutingNumber, '12345'],
]);

<?php

declare(strict_types=1);

use Firefly\Validation\Constraint\Bic;
use Firefly\Validation\Constraint\Constraint;
use Firefly\Validation\Constraint\CountryCode;
use Firefly\Validation\Constraint\CurrencyCode;
use Firefly\Validation\Constraint\Cusip;
use Firefly\Validation\Constraint\DecimalScale;
use Firefly\Validation\Constraint\HasMessage;
use Firefly\Validation\Constraint\Iban;
use Firefly\Validation\Constraint\Isin;
use Firefly\Validation\Constraint\LanguageTag;
use Firefly\Validation\Constraint\Luhn;
use Firefly\Validation\Constraint\Money;
use Firefly\Validation\Constraint\Percentage;
use Firefly\Validation\Constraint\Phone;
use Firefly\Validation\Constraint\PostalCode;
use Firefly\Validation\Constraint\RoutingNumber;
use Firefly\Validation\Constraint\Swift;
use Firefly\Validation\Constraint\UuidValue;
use Firefly\Validation\Rule\DecimalScale as DecimalScaleRule;

/**
 * The sentence each domain constraint publishes when it fails. None of these exists in Hibernate
 * Validator's ValidationMessages.properties (Jakarta has no @Iban), so the sentences follow its shape —
 * `must be a valid …`, naming the FORMAT the value failed to match — and never mention the attribute.
 */
it('publishes a sentence about the format, never about the attribute', function (Constraint&HasMessage $constraint, string $sentence) {
    expect($constraint->message())->toBe($sentence);
})->with([
    'Iban' => [new Iban, 'must be a valid IBAN'],
    'Bic' => [new Bic, 'must be a valid BIC'],
    'Swift' => [new Swift, 'must be a valid SWIFT code'],
    'Isin' => [new Isin, 'must be a valid ISIN'],
    'Cusip' => [new Cusip, 'must be a valid CUSIP'],
    'RoutingNumber' => [new RoutingNumber, 'must be a valid ABA routing number'],
    'Luhn' => [new Luhn, 'must pass the Luhn checksum'],
    'CurrencyCode' => [new CurrencyCode, 'must be a valid ISO 4217 currency code'],
    'CountryCode' => [new CountryCode, 'must be a valid ISO 3166-1 alpha-2 country code'],
    'LanguageTag' => [new LanguageTag, 'must be a valid BCP 47 language tag'],
    'UuidValue' => [new UuidValue, 'must be a valid UUID'],
    'Phone' => [new Phone, 'must be a valid E.164 phone number'],
    'PostalCode' => [new PostalCode, 'must be a valid postal code'],
    'Percentage' => [new Percentage, 'must be a percentage between 0 and 100'],
    'Money' => [new Money, 'must be a positive monetary amount with at most two decimals'],
    'DecimalScale' => [new DecimalScale(2), 'must have at most 2 fractional digits'],
]);

it('lets the message element win and fills {scale}', function () {
    expect((new Iban(message: 'not an IBAN we accept'))->message())->toBe('not an IBAN we accept')
        ->and((new Iban(message: 'not an IBAN we accept'))->hasCustomMessage())->toBeTrue()
        ->and((new Iban)->hasCustomMessage())->toBeFalse()
        ->and((new DecimalScale(3, message: 'max {scale} decimals'))->message())->toBe('max 3 decimals');
});

it('keeps the rule objects it compiles to', function () {
    // The sentences are new; the wrapped rule objects are not. DomainConstraintsTest pins each wrapper;
    // this pins that the message element is inert on the rule side.
    $rules = (new DecimalScale(3, message: 'x'))->toRules();

    expect($rules)->toHaveCount(1)
        ->and($rules[0])->toBeInstanceOf(DecimalScaleRule::class)
        ->and($rules[0]->scale())->toBe(3)
        ->and((new Iban(message: 'x'))->toRules())->toHaveCount(1);
});

<?php

declare(strict_types=1);

use Firefly\Validation\Constraint\ConstraintScanner;
use Firefly\Validation\Rule\Iban;
use Firefly\Validation\Rule\PositiveMoney;
use Firefly\Validation\Rule\PostalCode;
use Firefly\Validation\Tests\Fixtures\Constraint\MoneyTransferRequest;
use Firefly\Validation\Tests\Fixtures\Constraint\SelfReferential;

it('merges each promoted property constraint into declaration-ordered rules', function () {
    $rules = (new ConstraintScanner)->scan(MoneyTransferRequest::class);

    expect($rules['account'][0])->toBe('required')
        ->and($rules['account'][1])->toBe('string')
        ->and($rules['account'][2])->toBe('regex:/\S/')
        ->and($rules['account'][3])->toBeInstanceOf(Iban::class)
        ->and($rules['amount'][0])->toBeInstanceOf(PositiveMoney::class)
        ->and($rules['reference'])->toBe(['required', 'string', 'regex:/\S/', 'max:140']);
});

it('cascades one #[Valid] level into dot-prefixed nested keys', function () {
    $rules = (new ConstraintScanner)->scan(MoneyTransferRequest::class);

    expect($rules)->toHaveKey('beneficiary.street')
        ->and($rules)->toHaveKey('beneficiary.postcode')
        ->and($rules['beneficiary.postcode'][0])->toBeInstanceOf(PostalCode::class);
});

it('guards against #[Valid] cycles with a visited set', function () {
    $rules = (new ConstraintScanner)->scan(SelfReferential::class);

    expect($rules)->toHaveKey('label')
        ->and($rules)->toHaveKey('parent.label')
        // the cycle stops after one expansion — no parent.parent.* key
        ->and($rules)->not->toHaveKey('parent.parent.label');
});

it('returns an empty map for an interface or abstract class', function () {
    expect((new ConstraintScanner)->scan(Countable::class))->toBe([]);
});

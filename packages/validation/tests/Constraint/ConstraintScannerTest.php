<?php

declare(strict_types=1);

use Firefly\Validation\Constraint\ConstraintScanner;
use Firefly\Validation\Rule\Iban;
use Firefly\Validation\Rule\PositiveMoney;
use Firefly\Validation\Rule\PostalCode;
use Firefly\Validation\Rule\Size;
use Firefly\Validation\Tests\Fixtures\Constraint\MoneyTransferRequest;
use Firefly\Validation\Tests\Fixtures\Constraint\SelfReferential;

it('merges each promoted property constraint into declaration-ordered rules', function () {
    $rules = (new ConstraintScanner)->scan(MoneyTransferRequest::class);

    // `account` is declared `string`, not `?string`, so Jakarta's null contract does NOT add `nullable` to
    // it: the type has already said null is not a value this field can hold. See ConstraintScanner's
    // applyNullContract(), and JakartaNullSemanticsTest for the nullable-typed side of the same rule.
    expect($rules['account'][0])->toBe('required')
        ->and($rules['account'][1])->toBe('string')
        ->and($rules['account'][2])->toBe('regex:/\S/')
        ->and($rules['account'][3])->toBeInstanceOf(Iban::class)
        ->and($rules['account'])->not->toContain('nullable')
        ->and($rules['amount'][0])->toBeInstanceOf(PositiveMoney::class);
});

it('compiles #[Size] to a size-measuring rule object, never a polymorphic rule string', function () {
    $rules = (new ConstraintScanner)->scan(MoneyTransferRequest::class);

    $size = $rules['reference'][3];
    if (! $size instanceof Size) {
        throw new RuntimeException('expected #[Size] to compile to a Size rule object');
    }

    // `max:140` would have meant "the number is at most 140" the moment any sibling emitted `numeric`.
    expect($rules['reference'])->not->toContain('max:140')
        ->and($rules['reference'][0])->toBe('required')
        ->and($size->max())->toBe(140);
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

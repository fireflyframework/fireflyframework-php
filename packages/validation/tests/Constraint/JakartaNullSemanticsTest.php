<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Business\ValidationException;
use Firefly\Validation\Constraint\ConstraintScanner;
use Firefly\Validation\Tests\Fixtures\Constraint\MoneyTransferRequest;
use Firefly\Validation\Tests\Fixtures\Constraint\OptionalContactPayload;
use Firefly\Validation\Tests\Fixtures\Constraint\StrictContactPayload;
use Firefly\Validation\Tests\Fixtures\ValidatorHarness;

/**
 * @param  array<string,mixed>  $data
 * @return list<string> the fields that failed (empty === the payload validated)
 */
function contactFailures(array $data): array
{
    try {
        ValidatorHarness::beanValidator(OptionalContactPayload::class)->validate($data, OptionalContactPayload::class);

        return [];
    } catch (ValidationException $e) {
        return array_values(array_unique(array_map(static fn ($fieldError) => $fieldError->field, $e->fieldErrors())));
    }
}

/**
 * @param  array<string,mixed>  $data
 * @return list<string> the fields that failed (empty === the payload validated)
 */
function strictFailures(array $data): array
{
    try {
        ValidatorHarness::beanValidator(StrictContactPayload::class)->validate($data, StrictContactPayload::class);

        return [];
    } catch (ValidationException $e) {
        return array_values(array_unique(array_map(static fn ($fieldError) => $fieldError->field, $e->fieldErrors())));
    }
}

it('treats a present-but-null value as valid for every constraint except the null-rejecting ones', function () {
    expect(contactFailures([
        'primaryEmail' => 'ada@example.test',
        'backupEmail' => null,
        'displayName' => 'Ada',
    ]))->toBe([]);
});

it('makes an absent key and a present-null key agree', function () {
    $absent = contactFailures(['primaryEmail' => 'ada@example.test', 'displayName' => 'Ada']);
    $presentNull = contactFailures(['primaryEmail' => 'ada@example.test', 'backupEmail' => null, 'displayName' => 'Ada']);

    expect($absent)->toBe([])->and($presentNull)->toBe($absent);
});

it('still rejects null where #[NotNull] or #[NotBlank] asked for it', function () {
    expect(contactFailures(['primaryEmail' => null, 'displayName' => 'Ada']))->toBe(['primaryEmail'])
        ->and(contactFailures(['primaryEmail' => 'ada@example.test', 'displayName' => null]))->toBe(['displayName']);
});

it('still rejects an ABSENT key where #[NotNull] asked for presence', function () {
    expect(contactFailures(['displayName' => 'Ada']))->toBe(['primaryEmail']);
});

it('still rejects a non-null value that violates the constraint', function () {
    expect(contactFailures([
        'primaryEmail' => 'ada@example.test',
        'backupEmail' => 'not-an-email',
        'displayName' => 'Ada',
    ]))->toBe(['backupEmail']);
});

it('marks only the properties that no constraint guards against null', function () {
    $rules = (new ConstraintScanner)->scan(OptionalContactPayload::class);

    expect($rules['backupEmail'][0])->toBe('nullable')
        ->and($rules['primaryEmail'])->not->toContain('nullable')
        ->and($rules['displayName'][0])->toBe('nullable');
});

it('scopes the null contract to the property that declares the constraints, not to a nested subtree', function () {
    // Worth stating precisely, because it is the one place LaraFly still diverges from Jakarta. Jakarta would
    // read `beneficiary: null` as "nothing to cascade into" and pass: @Valid cascades, it does not require.
    // LaraFly flattens the cascade into dot-prefixed keys (`beneficiary.street`), and Illuminate runs IMPLICIT
    // rules — the `required` behind #[NotBlank] — whether or not the key exists, so the nested requirement
    // still fires from under a null parent.
    //
    // That is NOT the defect fixed here, and the fix deliberately does not reach it: the null contract is
    // per-PROPERTY, deciding what a present-but-null value means for the constraints declared ON that
    // property, and `beneficiary` declares none of its own. Suppressing a whole subtree would mean rewriting
    // each nested presence rule against its parent (`required` -> `required_with:beneficiary`), a separate
    // change with its own blast radius across the web layer's hydration. Pinned here so it stays a decision
    // rather than drifting.
    $payload = [
        'account' => 'GB82WEST12345698765432',
        'amount' => '19.99',
        'reference' => 'Invoice 42',
        'beneficiary' => null,
    ];

    try {
        ValidatorHarness::beanValidator(MoneyTransferRequest::class)->validate($payload, MoneyTransferRequest::class);
        throw new RuntimeException('expected the nested requirement to fire');
    } catch (ValidationException $e) {
        $fields = array_map(static fn ($fieldError) => $fieldError->field, $e->fieldErrors());

        expect($fields)->toContain('beneficiary.street')
            // ...and the null contract still holds for the top-level properties beside it.
            ->and($fields)->not->toContain('account');
    }
});

it('withholds the null contract from a property whose declared type cannot hold null', function () {
    // The contract is Jakarta's, but its precondition is PHP's: null is "a valid value for every constraint
    // but @NotNull" only where null is a value the property can actually take. A non-nullable `string` has
    // already refused null in its type, so #[Email] keeps rejecting an explicit null there — otherwise the
    // payload would pass validation and then die in the web layer's `new $dto(...$named)` with a TypeError,
    // downgrading a 422 into a 500. The nullable twin beside it still gets the skip.
    expect(strictFailures(['required' => null, 'optional' => 'ada@example.test']))->toBe(['required'])
        ->and(strictFailures(['required' => 'ada@example.test', 'optional' => null]))->toBe([])
        ->and(strictFailures(['required' => 'ada@example.test', 'optional' => 'nope']))->toBe(['optional']);

    $rules = (new ConstraintScanner)->scan(StrictContactPayload::class);
    expect($rules['required'])->not->toContain('nullable')
        ->and($rules['optional'][0])->toBe('nullable');
});

<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Business\ValidationException;
use Firefly\Validation\Constraint\ConstraintScanner;
use Firefly\Validation\Constraint\Size;
use Firefly\Validation\Rule\Size as SizeRule;
use Firefly\Validation\Tests\Fixtures\Constraint\SizedCodePayload;
use Firefly\Validation\Tests\Fixtures\ValidatorHarness;

/**
 * @param  array<string,mixed>  $data
 * @return list<string> the fields that failed (empty === the payload validated)
 */
function sizeFailures(array $data): array
{
    try {
        ValidatorHarness::beanValidator(SizedCodePayload::class)->validate($data, SizedCodePayload::class);

        return [];
    } catch (ValidationException $e) {
        return array_values(array_unique(array_map(static fn ($fieldError) => $fieldError->field, $e->fieldErrors())));
    }
}

it('keeps #[Size] on LENGTH semantics when a sibling constraint emits the numeric rule', function () {
    // 7 satisfies #[Min(5)] as a NUMBER and violates #[Size(min: 3)] as a LENGTH ("7" is one character).
    // Under Laravel's polymorphic between:/min:/max:, the sibling `numeric` rule flipped getSize() to the
    // value, so 3 <= 7 <= 8 held and the payload sailed through: a silently unenforced length constraint.
    expect(sizeFailures(['code' => 7, 'label' => 'ok']))->toBe(['code']);
});

it('does not let the numeric rule invent a #[Size] violation either', function () {
    // The mirror image, and the more damaging half: 12345678 is eight characters, comfortably inside
    // #[Size(min: 3, max: 8)], but as a NUMBER it dwarfs the max of 8 — so the old assembly rejected a
    // perfectly valid payload with a message about a length the value never violated.
    expect(sizeFailures(['code' => 12345678, 'label' => 'ok']))->toBe([]);
});

it('measures strings, arrays and countables by size and leaves untyped siblings alone', function () {
    expect(sizeFailures(['code' => 'abcde', 'label' => 'ok']))->toBe(['code'])   // 'abcde' is not numeric => #[Min(5)]
        ->and(sizeFailures(['code' => '12345', 'label' => 'abcd']))->toBe([])
        ->and(sizeFailures(['code' => '12345', 'label' => 'abcde']))->toBe(['label']);
});

it('compiles #[Size] to a first-party rule object rather than a polymorphic rule string', function () {
    $rules = (new ConstraintScanner)->scan(SizedCodePayload::class);

    $sizeRules = array_values(array_filter($rules['code'], static fn ($rule) => $rule instanceof SizeRule));
    expect($sizeRules)->toHaveCount(1)
        ->and($rules['code'])->not->toContain('between:3,8');

    $size = $sizeRules[0];
    expect($size->min())->toBe(3)->and($size->max())->toBe(8);
});

it('emits no rule at all for an unbounded #[Size]', function () {
    expect((new Size)->toRules())->toBe([]);
});

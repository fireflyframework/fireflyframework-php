<?php

declare(strict_types=1);

use Firefly\Validation\Constraint\ConstraintDescriptor;
use Firefly\Validation\Constraint\ConstraintScanner;
use Firefly\Validation\Constraint\NotBlank;
use Firefly\Validation\Constraint\Rules;
use Firefly\Validation\Rule\Iban;
use Firefly\Validation\Rule\NotNull as NotNullRule;
use Firefly\Validation\Rule\Size;
use Firefly\Validation\Tests\Fixtures\Constraint\MoneyTransferRequest;
use Firefly\Validation\Tests\Fixtures\Constraint\OptionalContactPayload;

it('describes each constraint on a property with its name, its sentence and the rule keys it contributed', function () {
    $constraints = (new ConstraintScanner)->constraints(MoneyTransferRequest::class);

    expect(array_keys($constraints))->toBe(array_keys((new ConstraintScanner)->scan(MoneyTransferRequest::class)))
        ->and(array_map(static fn (ConstraintDescriptor $d): string => $d->name, $constraints['account']))->toBe(['NotBlank', 'Iban'])
        ->and($constraints['account'][0]->message)->toBe('must not be blank')
        ->and($constraints['account'][0]->rules)->toBe([['Required', []], ['String', []], ['Regex', ['/\S/']]])
        ->and($constraints['account'][1]->rules)->toBe([[Iban::class, []]])
        ->and($constraints['reference'][1]->name)->toBe('Size')
        ->and($constraints['reference'][1]->message)->toBe('size must be at most 140')
        ->and($constraints['reference'][1]->rules)->toBe([[Size::class, []]])
        ->and($constraints['beneficiary.postcode'][0]->name)->toBe('PostalCode');
});

it('spells a parameterised string rule the way Validator::failed() will report it', function () {
    $constraints = (new ConstraintScanner)->constraints(OptionalContactPayload::class);

    // #[NotNull] compiles to `present` + the NotNull rule object; the scanner's `nullable` flag belongs to no
    // constraint and is therefore described by none.
    $primary = array_map(static fn (ConstraintDescriptor $d): array => [$d->name, $d->rules], $constraints['primaryEmail']);

    expect($primary)->toContain(['NotNull', [['Present', []], [NotNullRule::class, []]]])
        ->and($primary)->toContain(['Email', [['Email', []]]]);
});

it('hands a failed rule back to the constraint that owns it, by parameters first and by name second', function () {
    $notBlank = ConstraintDescriptor::of(new NotBlank, (new NotBlank)->toRules());
    $pattern = new ConstraintDescriptor('Pattern', 'must match "^[A-Z]+$"', [['Regex', ['/^[A-Z]+$/D']]]);

    expect($pattern->owns('Regex', ['/^[A-Z]+$/D']))->toBeTrue()
        ->and($notBlank->owns('Regex', ['/^[A-Z]+$/D']))->toBeFalse()
        ->and($notBlank->owns('Regex', ['/\S/']))->toBeTrue()
        ->and($notBlank->names('Regex'))->toBeTrue()
        ->and($notBlank->owns('Required', []))->toBeTrue()
        ->and($pattern->names('Required'))->toBeFalse()
        ->and($notBlank->name)->toBe('NotBlank');
});

it('has no sentence for a #[Rules] without a message, and the developer\'s — marked as their own — when given', function () {
    $bare = ConstraintDescriptor::of(new Rules('min:3', 'string'), ['min:3', 'string']);
    $worded = ConstraintDescriptor::of(new Rules('min:3', message: 'must be at least 3 characters'), ['min:3']);
    $defaulted = ConstraintDescriptor::of(new NotBlank, (new NotBlank)->toRules());
    $custom = ConstraintDescriptor::of(new NotBlank(message: 'give us a name'), (new NotBlank)->toRules());

    expect($bare->name)->toBe('Rules')
        ->and($bare->message)->toBeNull()
        ->and($bare->own)->toBeFalse()
        ->and($bare->rules)->toBe([['Min', ['3']], ['String', []]])
        ->and($worded->message)->toBe('must be at least 3 characters')
        ->and($worded->own)->toBeTrue()
        ->and($defaulted->own)->toBeFalse()
        ->and($custom->message)->toBe('give us a name')
        ->and($custom->own)->toBeTrue();
});

it('round-trips through the array form the manifest is written in', function () {
    $descriptor = new ConstraintDescriptor('Size', 'size must be between 1 and 50', [[Size::class, []]]);
    $custom = new ConstraintDescriptor('NotBlank', 'give us a name', [['Required', []]], own: true);

    expect($descriptor->toArray())->toBe(['name' => 'Size', 'message' => 'size must be between 1 and 50', 'rules' => [[Size::class, []]], 'own' => false])
        ->and(ConstraintDescriptor::fromArray($descriptor->toArray()))->toEqual($descriptor)
        ->and(ConstraintDescriptor::fromArray($custom->toArray()))->toEqual($custom)
        // A row written before `own` existed is a default sentence.
        ->and(ConstraintDescriptor::fromArray(['name' => 'Size', 'message' => 'x', 'rules' => []])->own)->toBeFalse();
});

<?php

declare(strict_types=1);

use Firefly\Validation\Constraint\ConstraintDescriptor;
use Firefly\Validation\Constraint\ConstraintManifest;
use Firefly\Validation\Constraint\ConstraintManifestCompiler;
use Firefly\Validation\Rule\DecimalScale;
use Firefly\Validation\Rule\Iban;
use Firefly\Validation\Rule\PostalCode;
use Firefly\Validation\Tests\Fixtures\Constraint\MoneyTransferRequest;

it('serialises rules to a pure-array envelope (strings + @rule entries)', function () {
    $rows = (new ConstraintManifestCompiler)->toArray([MoneyTransferRequest::class]);

    $account = $rows[MoneyTransferRequest::class]['account'];
    expect($account[0])->toBe('required')
        // A stateless rule keeps the bare two-key envelope: 'args' => [] would be noise in every manifest.
        ->and($account[3])->toBe(['@rule' => Iban::class])
        ->and($rows[MoneyTransferRequest::class]['beneficiary.postcode'][0])->toBe(['@rule' => PostalCode::class]);
});

it('round-trips a DecimalScale via the args envelope, rehydrating an identical rule', function () {
    $compiler = new ConstraintManifestCompiler;
    $rows = $compiler->toArray([DecimalDto::class]);

    // The scale is recovered from DecimalScale's PROMOTED constructor property, with no per-rule special
    // case in the compiler: the same generic path that carries a third-party rule's arguments.
    expect($rows[DecimalDto::class]['ratio'][0])->toBe(['@rule' => DecimalScale::class, 'args' => [3]]);

    $manifest = ConstraintManifest::fromArray($rows);
    $rehydrated = $manifest->rulesFor(DecimalDto::class)['ratio'][0];

    expect($rehydrated)->toBeInstanceOf(DecimalScale::class);

    if (! $rehydrated instanceof DecimalScale) {
        throw new RuntimeException('expected a rehydrated DecimalScale rule');
    }

    expect($rehydrated->scale())->toBe(3);
});

it('compiles to disk and reloads via require+map', function () {
    $path = sys_get_temp_dir().'/fc-constraints-'.bin2hex(random_bytes(6)).'.php';

    try {
        (new ConstraintManifestCompiler)->write([MoneyTransferRequest::class], $path);
        $manifest = ConstraintManifest::load($path);

        expect($manifest->rulesFor(MoneyTransferRequest::class)['account'][3])->toBeInstanceOf(Iban::class)
            ->and($manifest->rulesFor('App\\Unknown'))->toBe([]);
    } finally {
        @unlink($path);
    }
});

it('carries the constraint descriptors beside the rules, under the reserved @constraints key', function () {
    $rows = (new ConstraintManifestCompiler)->toArray([MoneyTransferRequest::class]);

    expect($rows)->toHaveKey(ConstraintManifest::CONSTRAINTS)
        ->and(array_key_last($rows))->toBe(ConstraintManifest::CONSTRAINTS)
        ->and($rows[ConstraintManifest::CONSTRAINTS][MoneyTransferRequest::class]['account'][0])
        ->toBe(['name' => 'NotBlank', 'message' => 'must not be blank', 'rules' => [['Required', []], ['String', []], ['Regex', ['/\S/']]], 'own' => false]);

    $manifest = ConstraintManifest::fromArray($rows);

    expect($manifest->constraintsFor(MoneyTransferRequest::class)['beneficiary.postcode'][0])->toBeInstanceOf(ConstraintDescriptor::class)
        ->and($manifest->constraintsFor(MoneyTransferRequest::class)['beneficiary.postcode'][0]->name)->toBe('PostalCode')
        ->and($manifest->rulesFor(MoneyTransferRequest::class))->toHaveKey('account')
        ->and($manifest->rulesFor(ConstraintManifest::CONSTRAINTS))->toBe([])
        ->and($manifest->constraintsFor('App\\Unknown'))->toBe([]);
});

it('loads a manifest written before descriptors existed, with no descriptors', function () {
    $manifest = ConstraintManifest::fromArray([MoneyTransferRequest::class => ['account' => ['required']]]);

    expect($manifest->rulesFor(MoneyTransferRequest::class))->toBe(['account' => ['required']])
        ->and($manifest->constraintsFor(MoneyTransferRequest::class))->toBe([]);
});

it('keeps the descriptors through the var_export round trip to disk', function () {
    $path = sys_get_temp_dir().'/fc-descriptors-'.bin2hex(random_bytes(6)).'.php';

    try {
        (new ConstraintManifestCompiler)->write([MoneyTransferRequest::class], $path);
        $loaded = ConstraintManifest::load($path);

        expect($loaded->constraintsFor(MoneyTransferRequest::class)['account'][1]->name)->toBe('Iban')
            ->and($loaded->constraintsFor(MoneyTransferRequest::class)['account'][1]->rules)->toBe([[Iban::class, []]]);
    } finally {
        @unlink($path);
    }
});

final class DecimalDto
{
    public function __construct(
        #[Firefly\Validation\Constraint\DecimalScale(3)]
        public readonly string $ratio,
    ) {}
}

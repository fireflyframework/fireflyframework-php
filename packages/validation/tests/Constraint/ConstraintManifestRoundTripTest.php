<?php

declare(strict_types=1);

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

final class DecimalDto
{
    public function __construct(
        #[Firefly\Validation\Constraint\DecimalScale(3)]
        public readonly string $ratio,
    ) {}
}

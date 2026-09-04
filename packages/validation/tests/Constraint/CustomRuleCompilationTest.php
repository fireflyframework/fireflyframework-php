<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Business\ValidationException;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Validation\Constraint\ConstraintManifest;
use Firefly\Validation\Constraint\ConstraintManifestCompiler;
use Firefly\Validation\Constraint\Rules;
use Firefly\Validation\Tests\Fixtures\Constraint\CustomRulePayload;
use Firefly\Validation\Tests\Fixtures\Rule\CutoffRule;
use Firefly\Validation\Tests\Fixtures\Rule\NormalisingRule;
use Firefly\Validation\Tests\Fixtures\Rule\OpaqueConstructorRule;
use Firefly\Validation\Tests\Fixtures\Rule\StartsWith;
use Firefly\Validation\Tests\Fixtures\ValidatorHarness;

it('serialises a promoted-constructor rule with its arguments', function () {
    $rows = (new ConstraintManifestCompiler)->toArray([CustomRulePayload::class]);

    expect($rows[CustomRulePayload::class]['sku'])
        ->toBe([['@rule' => StartsWith::class, 'args' => ['ACME-', true]]]);
});

it('rehydrates a custom rule that still carries its constructor state', function () {
    $rows = (new ConstraintManifestCompiler)->toArray([CustomRulePayload::class]);
    $manifest = ConstraintManifest::fromArray($rows);

    $sku = $manifest->rulesFor(CustomRulePayload::class)['sku'][0];
    expect($sku)->toBeInstanceOf(StartsWith::class);

    // The behavioural half of the round-trip: a rule rebuilt with a DEFAULTED constructor would have
    // accepted anything, so assert the compiled rule still enforces the prefix it was configured with.
    $validator = ValidatorHarness::beanValidator(CustomRulePayload::class);

    expect(fn () => $validator->validate(['sku' => 'OTHER-1', 'tag' => 'URGENT'], CustomRulePayload::class))
        ->toThrow(ValidationException::class);

    expect($validator->validate(['sku' => 'ACME-1', 'tag' => 'URGENT'], CustomRulePayload::class))
        ->toBe(['sku' => 'ACME-1', 'tag' => 'URGENT']);
});

it('survives the var_export round-trip to disk', function () {
    $path = sys_get_temp_dir().'/fc-custom-rules-'.bin2hex(random_bytes(6)).'.php';

    try {
        (new ConstraintManifestCompiler)->write([CustomRulePayload::class], $path);
        $rehydrated = ConstraintManifest::load($path)->rulesFor(CustomRulePayload::class)['tag'][0];

        expect($rehydrated)->toBeInstanceOf(NormalisingRule::class);
    } finally {
        @unlink($path);
    }
});

it('lets a rule declare its own constructor arguments when reflection cannot', function () {
    $rows = (new ConstraintManifestCompiler)->toArray([CustomRulePayload::class]);

    expect($rows[CustomRulePayload::class]['tag'])
        ->toBe([['@rule' => NormalisingRule::class, 'args' => ['URGENT']]]);
});

it('fails loudly at COMPILE time for a rule whose constructor state cannot be recovered', function () {
    expect(fn () => (new ConstraintManifestCompiler)->toArray([OpaqueRulePayload::class]))
        ->toThrow(ConfigurationException::class, OpaqueConstructorRule::class);
});

it('fails loudly at COMPILE time for constructor state var_export cannot write', function () {
    expect(fn () => (new ConstraintManifestCompiler)->toArray([CutoffRulePayload::class]))
        ->toThrow(ConfigurationException::class, CutoffRule::class);
});

final class OpaqueRulePayload
{
    public function __construct(
        #[Rules(new OpaqueConstructorRule('ACME'))]
        public readonly string $sku,
    ) {}
}

final class CutoffRulePayload
{
    public function __construct(
        #[Rules(new CutoffRule(new DateTimeImmutable('2030-01-01T00:00:00+00:00')))]
        public readonly string $issuedAt,
    ) {}
}

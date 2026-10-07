<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Evaluation\Fractional;
use Firefly\FeatureFlags\Tests\Support\FireflyVectors;

/*
 | The cross-language bucketing table: every key, every split, the bucket the reference evaluator chose. A key
 | for which fractional yields null ("" — no targeting key) resolves the flag's default variant, which the
 | vector document sets to the first weight's variant.
 */
it('puts every targeting key in the bucket the reference evaluator chose', function (array $case): void {
    /** @var array{flagKey: string, weights: list<array{0: string, 1: int}>, keys: list<string>, expect: list<string>} $case */
    $weights = $case['weights'];

    $actual = array_map(
        static fn (string $key): mixed => Fractional::evaluate(['$flagd' => ['flagKey' => $case['flagKey']], 'targetingKey' => $key], $weights) ?? $weights[0][0],
        $case['keys'],
    );

    expect(count($case['keys']))->toBeGreaterThanOrEqual(300)
        ->and($actual)->toBe($case['expect']);
})->with(fn (): array => FireflyVectors::cases('bucket'));

it('reads all five bucketing tables', function (): void {
    expect(FireflyVectors::cases('bucket'))->toHaveCount(5);
});

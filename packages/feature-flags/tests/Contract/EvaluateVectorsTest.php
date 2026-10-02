<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Definition\FlagDocument;
use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Evaluation\DefaultFlagdEvaluator;
use Firefly\FeatureFlags\Evaluation\FlagType;
use Firefly\FeatureFlags\Tests\Support\FireflyVectors;

/*
 | The Firefly half of the contract: each `evaluate` vector was resolved by the reference evaluator
 | (openfeature-flagd-core 1.0.0) over the document with its `$ref`s expanded the contract's way, and each
 | `bucket` table is the variant it chose for 301 targeting keys under five splits. A row that fails here is a
 | user who gets one answer from a PyFly service and another from a LaraFly one.
 */
it('evaluates every Firefly vector to the reference value, variant, reason and error code', function (array $case): void {
    /** @var array{document: mixed, flag: string, type: string, default: mixed, targetingKey?: string, context?: mixed, expect: mixed} $case */
    $expect = Json::members($case['expect']);

    $resolution = (new DefaultFlagdEvaluator)->evaluate(
        FlagDocument::fromJsonValue($case['document']),
        $case['flag'],
        FlagType::from($case['type']),
        $case['default'],
        $case['targetingKey'] ?? null,
        Json::members($case['context'] ?? null),
    );

    expect(Json::canonical($resolution->value))->toBe(Json::canonical($expect['value'] ?? null))
        ->and($resolution->variant)->toBe($expect['variant'] ?? null)
        ->and($resolution->reason->value)->toBe($expect['reason'] ?? null)
        ->and($resolution->error?->value)->toBe($expect['errorCode'] ?? null);
})->with(fn (): array => FireflyVectors::cases('evaluate'));

it('reads all fifty-one evaluate vectors', function (): void {
    expect(FireflyVectors::cases('evaluate'))->toHaveCount(51);
});

it('buckets every targeting key through the evaluator as the reference did', function (array $case): void {
    /** @var array{flagKey: string, weights: list<array{0: string, 1: int}>, keys: list<string>, expect: list<string>} $case */
    $flagKey = $case['flagKey'];
    $weights = $case['weights'];
    $variants = [];
    foreach ($weights as [$variant]) {
        $variants[$variant] = $variant;
    }
    $document = FlagDocument::fromJsonValue(['flags' => [$flagKey => [
        'state' => 'ENABLED', 'variants' => $variants, 'defaultVariant' => $weights[0][0], 'targeting' => ['fractional' => $weights],
    ]]]);
    $evaluator = new DefaultFlagdEvaluator;

    $actual = array_map(static fn (string $key): mixed => $evaluator->evaluate($document, $flagKey, FlagType::String, 'fallback', $key)->value, $case['keys']);

    expect(count($case['keys']))->toBe(301)
        ->and($actual)->toBe($case['expect']);
})->with(fn (): array => FireflyVectors::cases('bucket'));

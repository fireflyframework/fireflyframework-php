<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Definition\FlagDefinitions;
use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Registry\Composer;
use Firefly\FeatureFlags\Tests\Support\FireflyVectors;

it('composes layers per key exactly as the contract says', function (array $case): void {
    $layers = [];
    foreach ((array) $case['layers'] as $layer) {
        $members = Json::members($layer);
        $source = $members['source'] ?? null;
        expect($source)->toBeString();
        $layers[] = [is_string($source) ? $source : '', FlagDefinitions::parseDocument([
            'flags' => Json::object(FlagDefinitions::normalize(Json::members($members['flags'] ?? null))),
            '$evaluators' => $members['$evaluators'] ?? [],
        ])];
    }

    $composition = Composer::compose($layers);
    $expect = Json::members($case['expect']);
    $expectedFlags = Json::members($expect['flags'] ?? null);

    expect(array_map('strval', array_keys($composition->flags)))->toEqualCanonicalizing(array_map('strval', array_keys($expectedFlags)));

    foreach ($expectedFlags as $key => $flag) {
        $flag = Json::members($flag);
        $composed = $composition->flag((string) $key);

        expect($composed?->origin)->toBe($flag['origin'] ?? null)
            ->and($composed?->overrides)->toBe($flag['overrides'] ?? null)
            ->and(Json::canonical($composed?->definition->toArray()))->toBe(Json::canonical($flag['definition'] ?? null));
    }

    if (array_key_exists('evaluators', $expect)) {
        expect(Json::canonical($composition->evaluators))->toBe(Json::canonical($expect['evaluators']));
    }
})->with(fn (): array => FireflyVectors::cases('compose'));

it('runs every compose vector of the vendored contract', function (): void {
    expect(FireflyVectors::cases('compose'))->toHaveCount(4);
});

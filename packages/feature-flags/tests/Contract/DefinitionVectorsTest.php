<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Definition\FlagDefinitions;
use Firefly\FeatureFlags\Definition\FlagDocument;
use Firefly\FeatureFlags\Definition\InvalidFlagDefinition;
use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Tests\Support\FireflyVectors;

it('normalizes shorthand exactly as the contract tabulates', function (array $case): void {
    expect(Json::canonical(FlagDefinitions::normalize(Json::members($case['input']))))
        ->toBe(Json::canonical($case['expect']));
})->with(fn (): array => FireflyVectors::cases('normalize'));

it('validates with the contract rules and messages', function (array $case): void {
    /** @var array{valid: bool, key?: string, error?: string} $expect */
    $expect = Json::members($case['expect']);
    $error = null;

    try {
        $document = FlagDefinitions::parseDocument($case['input']);
    } catch (InvalidFlagDefinition $caught) {
        $error = $caught;
    }

    if ($expect['valid']) {
        expect($error)->toBeNull()->and($document ?? null)->toBeInstanceOf(FlagDocument::class);

        return;
    }

    expect($error)->toBeInstanceOf(InvalidFlagDefinition::class)
        ->and($error?->flagKey())->toBe($expect['key'] ?? null)
        ->and($error?->getMessage())->toContain($expect['error'] ?? '');
})->with(fn (): array => FireflyVectors::cases('validate'));

it('lists the expired flags of a document, sorted', function (array $case): void {
    $document = FlagDefinitions::parseDocument(['flags' => $case['flags']]);

    expect(FlagDefinitions::expiredKeys($document->flags, FireflyVectors::today()))
        ->toBe(Json::members($case['expect'])['expired'] ?? null);
})->with(fn (): array => FireflyVectors::cases('expiry'));

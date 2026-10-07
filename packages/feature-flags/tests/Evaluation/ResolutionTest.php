<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Evaluation\EvaluationError;
use Firefly\FeatureFlags\Evaluation\EvaluationReason;
use Firefly\FeatureFlags\Evaluation\FlagType;
use Firefly\FeatureFlags\Evaluation\Resolution;

it('builds OpenFeature resolution details from a match', function (): void {
    $details = (new Resolution(true, 'on', EvaluationReason::TargetingMatch, metadata: ['owner' => 'web']))->toResolutionDetails();

    expect($details->getValue())->toBeTrue()
        ->and($details->getVariant())->toBe('on')
        ->and($details->getReason())->toBe('TARGETING_MATCH')
        ->and($details->getError())->toBeNull();
});

it('carries an error code and message and never a variant on an error', function (): void {
    $details = Resolution::error(false, EvaluationError::FlagNotFound, 'Flag [x] is not defined.')->toResolutionDetails();

    expect($details->getValue())->toBeFalse()
        ->and($details->getVariant())->toBeNull()
        ->and($details->getReason())->toBe('ERROR')
        ->and($details->getError()?->getResolutionErrorCode()->getValue())->toBe('FLAG_NOT_FOUND')
        ->and($details->getError()?->getResolutionErrorMessage())->toBe('Flag [x] is not defined.');
});

it('carries numeric-looking metadata names as int keys whose (string) cast and JSON member names are the text', function (): void {
    // open-feature/sdk 2.3's ResolutionDetails has no metadata slot, so the names leave PHP only as text read
    // with (string) or as JSON member names; Json::object() keeps a list-like map ({"0": …}) an object.
    $named = new Resolution(true, 'on', EvaluationReason::Static, metadata: ['1' => 'one', '2024' => 2024, 'owner' => 'web']);
    $listLike = new Resolution(true, 'on', EvaluationReason::Static, metadata: ['0' => 'zero', '1' => 'one']);
    $names = array_map(static fn (int|string $name): string => (string) $name, array_keys($named->metadata));

    expect($named->metadata['1'] ?? null)->toBe('one')
        ->and($named->metadata['2024'] ?? null)->toBe(2024)
        ->and($names)->toBe(['1', '2024', 'owner'])
        ->and(Json::encode(Json::object($named->metadata)))->toBe('{"1":"one","2024":2024,"owner":"web"}')
        ->and(Json::encode(Json::object($listLike->metadata)))->toBe('{"0":"zero","1":"one"}')
        ->and($named->toResolutionDetails()->getVariant())->toBe('on')
        ->and($named->withValue(false)->metadata)->toBe($named->metadata);
});

it('hands OpenFeature arrays, never stdClass', function (): void {
    expect((new Resolution(new stdClass, 'empty', EvaluationReason::Static))->toResolutionDetails()->getValue())->toBe([]);
});

it('types values the way flagd checks them', function (): void {
    expect(FlagType::Float->accepts(1))->toBeTrue()
        ->and(FlagType::Integer->accepts(1.0))->toBeFalse()
        ->and(FlagType::Boolean->accepts(1))->toBeFalse()
        ->and(FlagType::Object->accepts(new stdClass))->toBeTrue()
        ->and(FlagType::Object->accepts([1]))->toBeTrue()
        ->and(FlagType::ofDefault(1.0))->toBe(FlagType::Float)
        ->and(FlagType::ofDefault([]))->toBe(FlagType::Object);
});

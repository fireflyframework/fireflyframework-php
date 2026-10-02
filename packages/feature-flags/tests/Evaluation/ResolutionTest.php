<?php

declare(strict_types=1);

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

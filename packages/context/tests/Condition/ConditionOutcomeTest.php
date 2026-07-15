<?php

declare(strict_types=1);

use Firefly\Context\Condition\ConditionOutcome;

it('constructs a matching outcome carrying a human-readable reason', function () {
    $outcome = ConditionOutcome::match('the property is present and truthy');

    expect($outcome->matched)->toBeTrue()
        ->and($outcome->reason)->toBe('the property is present and truthy');
});

it('constructs a non-matching outcome carrying a human-readable reason', function () {
    $outcome = ConditionOutcome::noMatch('the property did not match the required value');

    expect($outcome->matched)->toBeFalse()
        ->and($outcome->reason)->toBe('the property did not match the required value');
});

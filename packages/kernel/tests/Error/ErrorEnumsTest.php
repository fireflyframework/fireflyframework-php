<?php

declare(strict_types=1);

use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;

it('exposes stable string-backed error categories', function () {
    expect(ErrorCategory::Business->value)->toBe('business')
        ->and(ErrorCategory::Validation->value)->toBe('validation')
        ->and(ErrorCategory::Security->value)->toBe('security')
        ->and(ErrorCategory::Infrastructure->value)->toBe('infrastructure')
        ->and(ErrorCategory::External->value)->toBe('external')
        ->and(ErrorCategory::Framework->value)->toBe('framework')
        ->and(ErrorCategory::Plugin->value)->toBe('plugin')
        ->and(ErrorCategory::Internal->value)->toBe('internal');
});

it('exposes stable string-backed severities', function () {
    expect(ErrorSeverity::Info->value)->toBe('info')
        ->and(ErrorSeverity::Warning->value)->toBe('warning')
        ->and(ErrorSeverity::Error->value)->toBe('error')
        ->and(ErrorSeverity::Critical->value)->toBe('critical');
});

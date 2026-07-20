<?php

declare(strict_types=1);

use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Exception\Infrastructure\InfrastructureException;
use Firefly\Resilience\Exception\BulkheadFullException;

it('is an InfrastructureException with a 503 BULKHEAD_FULL warning default', function () {
    $e = new BulkheadFullException;

    expect($e)->toBeInstanceOf(InfrastructureException::class)
        ->and($e->getMessage())->toBe('Bulkhead is full')
        ->and($e->errorCode())->toBe('BULKHEAD_FULL')
        ->and($e->httpStatus())->toBe(503)
        ->and($e->category())->toBe(ErrorCategory::Infrastructure)
        ->and($e->severity())->toBe(ErrorSeverity::Warning);
});

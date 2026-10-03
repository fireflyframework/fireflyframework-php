<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Gating;

use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Exception\FireflyException;

/** A refusal whose public problem response never reveals the flag key. */
final class FeatureFlagDisabledException extends FireflyException
{
    public function __construct(private readonly string $flagKey, int $status = 404)
    {
        [$code, $message, $category, $httpStatus] = match ($status) {
            403 => ['ACCESS_DENIED', 'Access denied', ErrorCategory::Security, 403],
            503 => ['SERVICE_UNAVAILABLE', 'Service unavailable', ErrorCategory::Infrastructure, 503],
            default => ['RESOURCE_NOT_FOUND', 'Resource not found', ErrorCategory::Business, 404],
        };

        parent::__construct($message, $code, $httpStatus, $category, ErrorSeverity::Info);
    }

    public function flagKey(): string
    {
        return $this->flagKey;
    }
}

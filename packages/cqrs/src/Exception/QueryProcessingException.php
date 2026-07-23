<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Exception;

use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Exception\FireflyException;
use Throwable;

/**
 * The query-side twin of CommandProcessingException: DefaultQueryBus wraps any handler/stage throwable in this
 * (unless already one), preserving a FireflyException cause's httpStatus/category/severity, else internal/500.
 */
final class QueryProcessingException extends CqrsException
{
    public function __construct(string $queryClass, Throwable $cause)
    {
        parent::__construct(
            "Processing query [{$queryClass}] failed: {$cause->getMessage()}",
            'QUERY_PROCESSING_ERROR',
            $cause instanceof FireflyException ? $cause->httpStatus() : 500,
            $cause instanceof FireflyException ? $cause->category() : ErrorCategory::Internal,
            $cause instanceof FireflyException ? $cause->severity() : ErrorSeverity::Error,
            $cause,
        );
    }
}

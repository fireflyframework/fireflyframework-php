<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Exception;

use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;
use Throwable;

/**
 * Thrown by HandlerRegistry::findQueryHandler when no handler is registered for a query class — a framework-category
 * 500 (an unmapped query sent through the bus).
 */
final class QueryHandlerNotFoundException extends CqrsException
{
    public function __construct(string $queryClass, ?Throwable $previous = null)
    {
        parent::__construct(
            "No query handler registered for [{$queryClass}].",
            'QUERY_HANDLER_NOT_FOUND',
            500,
            ErrorCategory::Framework,
            ErrorSeverity::Error,
            $previous,
        );
    }
}

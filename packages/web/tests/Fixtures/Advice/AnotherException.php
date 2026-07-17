<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures\Advice;

use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Exception\FireflyException;

/** httpStatus 400 — a distinct global-handled exception with no controller-local handler. */
final class AnotherException extends FireflyException
{
    public function __construct(string $message = 'Another error')
    {
        parent::__construct($message, 'ANOTHER_ERROR', 400, ErrorCategory::Validation, ErrorSeverity::Warning);
    }
}

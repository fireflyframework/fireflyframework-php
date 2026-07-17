<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures\Advice;

use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Exception\FireflyException;

/** httpStatus 409 — distinct from the route's default 200, so the matched-handler status is observable. */
final class CustomBusinessException extends FireflyException
{
    public function __construct(string $message = 'Conflict')
    {
        parent::__construct($message, 'CUSTOM_BUSINESS', 409, ErrorCategory::Business, ErrorSeverity::Warning);
    }
}

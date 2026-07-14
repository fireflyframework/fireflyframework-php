<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Business;

use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Error\FieldError;
use Throwable;

class ValidationException extends BusinessException
{
    /**
     * @param  list<FieldError>  $fieldErrors
     */
    public function __construct(
        string $message = 'Validation failed',
        private readonly array $fieldErrors = [],
        string $errorCode = 'VALIDATION_ERROR',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, 422, ErrorCategory::Validation, ErrorSeverity::Warning, $previous);
    }

    /**
     * @return list<FieldError>
     */
    public function fieldErrors(): array
    {
        return $this->fieldErrors;
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core\Exception;

use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Exception\FireflyException;
use Throwable;

/**
 * Base of every refusal the orchestration engines raise. A failed STEP is never thrown out of an engine — it
 * is reported in the result — so what reaches a caller as an exception is an infrastructure answer (unknown
 * definition, missing execution, locked, disabled, back-pressured) carrying an HTTP status the REST surface
 * and the web layer render as problem+json.
 */
class OrchestrationException extends FireflyException
{
    public function __construct(string $message, string $errorCode = 'ORCHESTRATION_ERROR', int $httpStatus = 500, ?Throwable $previous = null)
    {
        parent::__construct($message, $errorCode, $httpStatus, ErrorCategory::Internal, ErrorSeverity::Error, $previous);
    }
}

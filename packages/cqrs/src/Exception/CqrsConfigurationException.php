<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Exception;

use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;
use Throwable;

/**
 * Fail-loud framework misconfiguration surfaced at SCAN time (an #[CommandHandler] whose message type cannot be
 * inferred, a handler with no/multi-param handle()) or at WIRING time (two handlers claim the same message class).
 * Critical severity: it must abort the build/boot, never silently skip a handler.
 */
final class CqrsConfigurationException extends CqrsException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 'CQRS_CONFIGURATION_ERROR', 500, ErrorCategory::Framework, ErrorSeverity::Critical, $previous);
    }
}

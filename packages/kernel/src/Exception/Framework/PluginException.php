<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Framework;

use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Exception\FireflyException;
use Throwable;

class PluginException extends FireflyException
{
    public function __construct(
        string $message,
        string $errorCode = 'PLUGIN_ERROR',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, 500, ErrorCategory::Plugin, ErrorSeverity::Error, $previous);
    }
}

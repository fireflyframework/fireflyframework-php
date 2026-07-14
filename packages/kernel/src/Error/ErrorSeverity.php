<?php

declare(strict_types=1);

namespace Firefly\Kernel\Error;

enum ErrorSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';
}

<?php

declare(strict_types=1);

namespace Firefly\Kernel\Error;

enum ErrorCategory: string
{
    case Business = 'business';
    case Validation = 'validation';
    case Security = 'security';
    case Infrastructure = 'infrastructure';
    case External = 'external';
    case Framework = 'framework';
    case Plugin = 'plugin';
    case Internal = 'internal';
}

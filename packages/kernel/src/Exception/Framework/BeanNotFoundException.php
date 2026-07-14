<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Framework;

use Throwable;

class BeanNotFoundException extends ConfigurationException
{
    public function __construct(
        string $message,
        string $errorCode = 'BEAN_NOT_FOUND',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, previous: $previous);
    }
}

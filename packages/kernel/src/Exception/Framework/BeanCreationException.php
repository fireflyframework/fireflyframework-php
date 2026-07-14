<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Framework;

use Throwable;

class BeanCreationException extends ConfigurationException
{
    public function __construct(
        string $message,
        string $errorCode = 'BEAN_CREATION_ERROR',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, previous: $previous);
    }
}

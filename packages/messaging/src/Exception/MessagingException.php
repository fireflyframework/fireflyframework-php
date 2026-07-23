<?php

declare(strict_types=1);

namespace Firefly\Messaging\Exception;

use Firefly\Kernel\Exception\Infrastructure\InfrastructureException;
use Throwable;

/**
 * Thrown for messaging transport misuse (publishing before start(), or a misconfigured queue worker). Extends the
 * frozen kernel InfrastructureException (httpStatus 500). Fail-loud, never silent.
 */
final class MessagingException extends InfrastructureException
{
    public function __construct(string $message, string $errorCode = 'MESSAGING_ERROR', ?Throwable $previous = null)
    {
        parent::__construct($message, $errorCode, 500, previous: $previous);
    }
}

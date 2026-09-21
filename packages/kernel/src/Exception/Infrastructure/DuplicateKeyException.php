<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Infrastructure;

use Throwable;

/**
 * A unique index or primary key already holds the value being written. The most common integrity failure by a
 * wide margin, and the one a client most often wants to tell apart ("that email is taken"), so it has its own
 * type and code under DataIntegrityViolationException. Spring's DuplicateKeyException.
 */
class DuplicateKeyException extends DataIntegrityViolationException
{
    public function __construct(
        string $message = 'A row with the same unique key already exists.',
        string $errorCode = 'DUPLICATE_KEY',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $previous);
    }
}

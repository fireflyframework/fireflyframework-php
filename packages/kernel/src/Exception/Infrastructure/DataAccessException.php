<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Infrastructure;

use Firefly\Kernel\Error\ErrorSeverity;
use Throwable;

/**
 * The root of the data-access family — Spring's DataAccessException, ported. Application code catches at
 * the granularity it needs: this class when "the database is not my problem", DataIntegrityViolationException
 * when any constraint failure reads the same, DuplicateKeyException when it wants to say "that name is taken".
 *
 * firefly/data's PersistenceExceptionTranslator produces the subclasses from a driver's SQLSTATE and error
 * code; they carry the HTTP status the failure implies (a duplicate key is the client's 409, an unreachable
 * database the platform's 503) so problem+json needs no per-controller mapping. The status and severity are
 * trailing, optional constructor parameters so every existing positional caller of the three-argument form
 * keeps compiling — subclasses fix them, application code never passes them.
 *
 * A translated message is a fixed sentence. The driver's own message carries the statement with its bindings
 * interpolated, and this class's message becomes the problem document's `detail`, so the driver's text stays
 * on `previous` for the log and never on the wire.
 */
class DataAccessException extends InfrastructureException
{
    public function __construct(
        string $message = 'Data access error',
        string $errorCode = 'DATA_ACCESS_ERROR',
        ?Throwable $previous = null,
        int $httpStatus = 500,
        ErrorSeverity $severity = ErrorSeverity::Error,
    ) {
        parent::__construct($message, $errorCode, $httpStatus, $severity, $previous);
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Infrastructure;

use Throwable;

/**
 * The statement itself was rejected — a syntax error, an unknown table or column. A programming or migration
 * error, never the client's, so 500. The statement stays on `previous` (a QueryException); this message never
 * repeats it. Spring's BadSqlGrammarException.
 */
class BadSqlGrammarException extends DataAccessException
{
    public function __construct(
        string $message = 'The statement was rejected by the database.',
        string $errorCode = 'BAD_SQL_GRAMMAR',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $previous);
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Eda;

/**
 * The error-handling strategies an eda listener/adapter can apply (pyfly eda/types.py parity). M9 ships the
 * DEAD_LETTER + RETRY behaviour via RetryingEventHandler + DeadLetterStore; the enum is the documented seam a
 * broker adapter or a future per-listener policy selects from. A backed enum so it serialises as a plain string.
 */
enum ErrorStrategy: string
{
    case IGNORE = 'ignore';
    case LOG_AND_CONTINUE = 'log_and_continue';
    case RETRY = 'retry';
    case DEAD_LETTER = 'dead_letter';
    case FAIL_FAST = 'fail_fast';
}

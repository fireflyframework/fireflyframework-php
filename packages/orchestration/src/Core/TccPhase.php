<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core;

/** The three phases of a TCC transaction; the current one is checkpointed so resume continues from it. */
enum TccPhase: string
{
    case Try = 'TRY';
    case Confirm = 'CONFIRM';
    case Cancel = 'CANCEL';
}

<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core;

/**
 * The state of one step or participant. A SETTLED step is never run again — a resumed execution skips it —
 * which is what makes resume after a crash at-least-once per unsettled step and exactly-once per settled one.
 */
enum StepStatus: string
{
    case Pending = 'PENDING';
    case Running = 'RUNNING';
    case Waiting = 'WAITING';
    case Done = 'DONE';
    case Failed = 'FAILED';
    case Skipped = 'SKIPPED';
    case TimedOut = 'TIMED_OUT';
    case Compensated = 'COMPENSATED';
    case CompensationFailed = 'COMPENSATION_FAILED';
    case Confirmed = 'CONFIRMED';
    case ConfirmFailed = 'CONFIRM_FAILED';
    case Canceled = 'CANCELED';
    case CancelFailed = 'CANCEL_FAILED';

    public function isSettled(): bool
    {
        return in_array($this, [self::Done, self::Skipped, self::Compensated, self::Confirmed, self::Canceled], true);
    }

    public function isFailure(): bool
    {
        return in_array($this, [self::Failed, self::TimedOut, self::CompensationFailed, self::ConfirmFailed, self::CancelFailed], true);
    }
}

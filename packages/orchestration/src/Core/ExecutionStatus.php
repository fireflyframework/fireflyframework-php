<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core;

/**
 * The lifecycle of an execution, shared by the three engines (jafly ExecutionStatus parity). Sagas use
 * RUNNING → COMPENSATING → COMPLETED|FAILED; TCC uses TRYING → CONFIRMING → CONFIRMED, or CANCELING →
 * CANCELED|FAILED; workflows add PENDING (queued), WAITING (a signal, timer or child) and SUSPENDED.
 *
 * "Recoverable" is the set a crashed process can leave behind and the recovery sweep may act on. WAITING and
 * SUSPENDED are deliberately NOT in it: an execution waiting a week for an approval signal is idle by design,
 * and resuming it would be a bug, not a recovery.
 */
enum ExecutionStatus: string
{
    case Pending = 'PENDING';
    case Running = 'RUNNING';
    case Waiting = 'WAITING';
    case Suspended = 'SUSPENDED';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';
    case TimedOut = 'TIMED_OUT';
    case Trying = 'TRYING';
    case Confirming = 'CONFIRMING';
    case Confirmed = 'CONFIRMED';
    case Canceling = 'CANCELING';
    case Canceled = 'CANCELED';
    case Compensating = 'COMPENSATING';

    public function isTerminal(): bool
    {
        return in_array($this, self::terminal(), true);
    }

    public function isSuccess(): bool
    {
        return $this === self::Completed || $this === self::Confirmed;
    }

    public function isRecoverable(): bool
    {
        return in_array($this, self::recoverable(), true);
    }

    public function canSuspend(): bool
    {
        return $this === self::Pending || $this === self::Running || $this === self::Waiting;
    }

    public function canResume(): bool
    {
        return $this === self::Suspended;
    }

    /** @return list<self> */
    public static function terminal(): array
    {
        return [self::Completed, self::Failed, self::Cancelled, self::TimedOut, self::Confirmed, self::Canceled];
    }

    /** @return list<self> */
    public static function recoverable(): array
    {
        return [self::Pending, self::Running, self::Compensating, self::Trying, self::Confirming, self::Canceling];
    }
}

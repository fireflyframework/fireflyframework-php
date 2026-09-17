<?php

declare(strict_types=1);

namespace Firefly\Scheduling\Schedule;

use Firefly\Resilience\Duration;
use Illuminate\Console\Scheduling\Event;

/**
 * How often a #[Scheduled] task REALLY runs — the one table that maps a descriptor's trigger onto a native
 * Laravel cadence, used both to wire the Schedule and to tell a person what was wired.
 *
 * It is one class rather than a private method on ScheduleWiringPass because two things must agree on the
 * answer: the pass that registers the event, and `firefly:schedule`, which prints the cadence beside each
 * task. A listing that echoed the attribute ("7s") while the pass ran the task every ten seconds would be a
 * second source of truth, and the wrong one.
 *
 * ROUNDING IS UP, NEVER DOWN. Laravel has no arbitrary-interval DSL: below a minute it offers the divisors of
 * sixty (1, 2, 5, 10, 15, 20, 30 seconds, honoured by `schedule:work` re-running the event inside the minute)
 * and above it a fixed set of buckets. A rate between two buckets lands on the next slower one, because a task
 * that runs MORE often than it declared is the surprise nobody budgeted for. Everything at or under sixty
 * seconds used to land on everyMinute(), so `fixedRate: '10s'` ran six times less often than it said and an
 * application that needed a ten-second sweep wrote its own loop; the sub-minute half of the table closes that.
 */
final readonly class Cadence
{
    /** @var list<int> the sub-minute cadences Laravel supports, ascending */
    private const array SUB_MINUTE = [1, 2, 5, 10, 15, 20, 30];

    private function __construct(
        public ?string $cron,
        public ?int $repeatSeconds,
        public ?int $everySeconds,
    ) {}

    public static function of(ScheduledDescriptor $descriptor): self
    {
        if ($descriptor->cron !== null) {
            return new self($descriptor->cron, null, null);
        }

        $seconds = Duration::parse((string) ($descriptor->fixedRate ?? $descriptor->fixedDelay));

        foreach (self::SUB_MINUTE as $bucket) {
            if ($seconds <= $bucket) {
                return new self(null, $bucket, $bucket);
            }
        }

        $every = match (true) {
            $seconds <= 60.0 => 60,
            $seconds <= 300.0 => 300,
            $seconds <= 600.0 => 600,
            $seconds <= 900.0 => 900,
            $seconds <= 1800.0 => 1800,
            $seconds <= 3600.0 => 3600,
            $seconds <= 86400.0 => 86400,
            default => 604800,
        };

        return new self(null, null, $every);
    }

    public function apply(Event $event): void
    {
        if ($this->cron !== null) {
            $event->cron($this->cron);

            return;
        }

        match ($this->everySeconds) {
            1 => $event->everySecond(),
            2 => $event->everyTwoSeconds(),
            5 => $event->everyFiveSeconds(),
            10 => $event->everyTenSeconds(),
            15 => $event->everyFifteenSeconds(),
            20 => $event->everyTwentySeconds(),
            30 => $event->everyThirtySeconds(),
            60 => $event->everyMinute(),
            300 => $event->everyFiveMinutes(),
            600 => $event->everyTenMinutes(),
            900 => $event->everyFifteenMinutes(),
            1800 => $event->everyThirtyMinutes(),
            3600 => $event->hourly(),
            86400 => $event->daily(),
            default => $event->weekly(),
        };
    }

    /** "every 10 seconds", "every 5 minutes", "hourly", "daily", "weekly", or the cron expression verbatim. */
    public function describe(): string
    {
        if ($this->cron !== null) {
            return $this->cron;
        }

        return match ($this->everySeconds) {
            1 => 'every second',
            60 => 'every minute',
            3600 => 'hourly',
            86400 => 'daily',
            604800 => 'weekly',
            default => $this->everySeconds !== null && $this->everySeconds < 60
                ? "every {$this->everySeconds} seconds"
                : 'every '.intdiv((int) $this->everySeconds, 60).' minutes',
        };
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Resilience;

use Firefly\Kernel\Exception\Infrastructure\TimeoutException;

/**
 * Best-effort timeout. Where pcntl is available AND the timeout is a whole second or more (pcntl_alarm's
 * granularity — sub-second timeouts round up to 1s, so anything below 1s uses the post-hoc path instead),
 * a SIGALRM handler interrupts a genuinely blocking call. Everywhere else — sub-second timeouts, and
 * PHP-FPM, which has no pcntl — the limiter can only measure the elapsed wall-clock AFTER the callable
 * returns and throw if it overran (it cannot preempt a blocking FPM call; see docs/known-latent).
 *
 * pcntl availability is exactly the condition under which Laravel's queue worker installs its own SIGALRM
 * handler + arms pcntl_alarm for its --timeout runaway-job safety net. So arm()/disarm() capture and RESTORE
 * the caller's prior handler and pending alarm (never clobbering them to SIG_DFL / cancelling them), keeping
 * a TimeLimiter used inside a queued job worker-safe. State is threaded through locals so the limiter stays
 * effectively stateless / reentrant.
 */
final class TimeLimiter
{
    public function __construct(private readonly float $timeout = 30.0) {}

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function call(callable $callback): mixed
    {
        $start = microtime(true);
        $state = $this->arm();

        try {
            $result = $callback();
        } finally {
            $this->disarm($state);
        }

        if ((microtime(true) - $start) > $this->timeout) {
            throw new TimeoutException;
        }

        return $result;
    }

    /**
     * Arm a SIGALRM-backed timeout, capturing the caller's prior handler + pending alarm so disarm() can
     * restore them. Returns null on the non-pcntl / sub-second post-hoc path (nothing to disarm), otherwise
     * a [previousHandler, previousAlarmRemaining] tuple.
     *
     * @return array{0: callable|int, 1: int}|null
     */
    private function arm(): ?array
    {
        if ($this->timeout < 1.0) {
            return null;
        }
        if (! function_exists('pcntl_async_signals') || ! function_exists('pcntl_alarm') || ! function_exists('pcntl_signal') || ! function_exists('pcntl_signal_get_handler')) {
            return null;
        }

        pcntl_async_signals(true);

        // Capture the caller's handler BEFORE installing ours (e.g. the queue worker's --timeout handler).
        /** @var callable|int $previousHandler */
        $previousHandler = pcntl_signal_get_handler(SIGALRM);

        pcntl_signal(SIGALRM, static function (): void {
            throw new TimeoutException;
        });

        // pcntl_alarm() returns the seconds remaining on any alarm it just replaced (0 if none) — that is the
        // caller's pending deadline, which disarm() re-arms.
        $previousAlarmRemaining = pcntl_alarm(max(1, (int) ceil($this->timeout)));

        return [$previousHandler, $previousAlarmRemaining];
    }

    /**
     * @param  array{0: callable|int, 1: int}|null  $state
     */
    private function disarm(?array $state): void
    {
        if ($state === null) {
            return;
        }

        [$previousHandler, $previousAlarmRemaining] = $state;

        pcntl_alarm(0);                             // cancel our own alarm first
        pcntl_signal(SIGALRM, $previousHandler);    // restore the caller's handler (never SIG_DFL)

        if ($previousAlarmRemaining > 0) {
            // Re-arm the caller's alarm (approximately — slightly generous beats cancelling their safety net).
            pcntl_alarm($previousAlarmRemaining);
        }
    }
}

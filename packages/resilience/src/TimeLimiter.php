<?php

declare(strict_types=1);

namespace Firefly\Resilience;

use Firefly\Kernel\Exception\Infrastructure\TimeoutException;

/**
 * Best-effort timeout. Where pcntl is available AND the timeout is a whole second or more (pcntl_alarm's
 * granularity), a SIGALRM handler interrupts a genuinely blocking call. Everywhere else — sub-second
 * timeouts, and PHP-FPM, which has no pcntl — the limiter can only measure the elapsed wall-clock AFTER the
 * callable returns and throw if it overran (it cannot preempt a blocking FPM call; see docs/known-latent).
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
        $armed = $this->arm();

        try {
            $result = $callback();
        } finally {
            $this->disarm($armed);
        }

        if ((microtime(true) - $start) > $this->timeout) {
            throw new TimeoutException;
        }

        return $result;
    }

    private function arm(): bool
    {
        if ($this->timeout < 1.0) {
            return false;
        }
        if (! function_exists('pcntl_async_signals') || ! function_exists('pcntl_alarm') || ! function_exists('pcntl_signal')) {
            return false;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function (): void {
            throw new TimeoutException;
        });
        pcntl_alarm((int) ceil($this->timeout));

        return true;
    }

    private function disarm(bool $armed): void
    {
        if (! $armed) {
            return;
        }

        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);
    }
}

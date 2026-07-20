<?php

declare(strict_types=1);

namespace Firefly\Resilience;

use Throwable;

/**
 * Stateless retry: re-invokes a callable up to maxAttempts while it throws a retry-on error, backing off
 * waitDuration * backoffMultiplier^(n-1) (capped at maxWait, plus up to jitter seconds). Directly
 * constructable (decorator-style) since it holds no cross-request state.
 */
final class Retry
{
    /** @param  list<class-string<Throwable>>  $retryOn */
    public function __construct(
        private readonly int $maxAttempts = 3,
        private readonly float $waitDuration = 0.0,
        private readonly float $backoffMultiplier = 1.0,
        private readonly ?float $maxWait = null,
        private readonly float $jitter = 0.0,
        private readonly array $retryOn = [Throwable::class],
    ) {}

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function call(callable $callback): mixed
    {
        $attempt = 0;

        while (true) {
            $attempt++;
            try {
                return $callback();
            } catch (Throwable $e) {
                if (! $this->retryable($e) || $attempt >= $this->maxAttempts) {
                    throw $e;
                }
                $this->backoff($attempt);
            }
        }
    }

    private function retryable(Throwable $e): bool
    {
        foreach ($this->retryOn as $type) {
            if ($e instanceof $type) {
                return true;
            }
        }

        return false;
    }

    private function backoff(int $attempt): void
    {
        if ($this->waitDuration <= 0.0) {
            return;
        }

        $wait = $this->waitDuration * ($this->backoffMultiplier ** ($attempt - 1));
        if ($this->maxWait !== null) {
            $wait = min($wait, $this->maxWait);
        }
        if ($this->jitter > 0.0) {
            $wait += (mt_rand() / mt_getrandmax()) * $this->jitter;
        }

        usleep((int) round($wait * 1_000_000));
    }
}

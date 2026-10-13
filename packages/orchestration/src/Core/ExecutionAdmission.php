<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core;

use Closure;
use Firefly\Orchestration\Core\Exception\BackpressureException;
use Firefly\Orchestration\Persistence\ExecutionPersistenceProvider;
use Firefly\Orchestration\Settings\BackpressureSettings;
use Firefly\Resilience\CircuitBreaker;

/**
 * Back-pressure at the door of every NEW execution (spec decision D8). `max-in-flight` refuses a start while
 * that many executions are open (a 503, BackpressureException). With `strategy: circuit-breaker` the start
 * runs inside a firefly/resilience CircuitBreaker that counts executions ending FAILED, so a dependency that
 * fails every saga sheds load for `recovery-timeout` instead of being hammered; while it is open the start
 * throws the breaker's CircuitBreakerOpenException. Resume, signal and tick are never refused: they advance
 * executions that are already admitted.
 */
final class ExecutionAdmission
{
    public function __construct(
        private readonly ExecutionPersistenceProvider $persistence,
        private readonly BackpressureSettings $settings,
        private readonly ?CircuitBreaker $breaker = null,
    ) {}

    /**
     * @template T of object
     *
     * @param  Closure(): T  $execution
     * @param  Closure(T): bool  $succeeded
     * @return T
     */
    public function admit(string $name, Closure $execution, Closure $succeeded): object
    {
        if ($this->settings->maxInFlight > 0 && $this->persistence->countInFlight() >= $this->settings->maxInFlight) {
            throw new BackpressureException("Orchestration [{$name}] refused: {$this->settings->maxInFlight} executions are already in flight (firefly.orchestration.backpressure.max-in-flight).");
        }

        if ($this->breaker === null) {
            return $execution();
        }

        try {
            return $this->breaker->call(static function () use ($execution, $succeeded): object {
                $result = $execution();
                if (! $succeeded($result)) {
                    throw new FailedExecution($result);
                }

                return $result;
            });
        } catch (FailedExecution $failed) {
            /** @var T */
            return $failed->result;
        }
    }
}

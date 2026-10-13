<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core;

use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * How a failed saga undoes its completed steps (jafly CompensationPolicy parity).
 *
 * PHP runs one thing at a time, so the two "parallel" policies keep their ORDER semantics and run
 * sequentially: GROUPED_PARALLEL compensates layer by layer in reverse (steps of one layer in declaration
 * order), BEST_EFFORT compensates every completed step in reverse and never stops on a failure. jafly's
 * BEST_EFFORT_PARALLEL is accepted as an alias of BEST_EFFORT, and every spelling (kebab, snake, SCREAMING)
 * reads the same, so a value copied from a Spring application.yml works unchanged.
 */
enum CompensationPolicy: string
{
    case StrictSequential = 'strict-sequential';
    case GroupedParallel = 'grouped-parallel';
    case RetryWithBackoff = 'retry-with-backoff';
    case CircuitBreaker = 'circuit-breaker';
    case BestEffort = 'best-effort';

    public static function fromConfig(string $value, string $key): self
    {
        $normalized = strtolower(str_replace('_', '-', trim($value)));
        if ($normalized === 'best-effort-parallel') {
            return self::BestEffort;
        }

        return self::tryFrom($normalized) ?? throw new ConfigurationException(
            "Configuration key [{$key}] must be one of strict-sequential, grouped-parallel, retry-with-backoff, "
            ."circuit-breaker or best-effort, got [{$value}].",
        );
    }
}

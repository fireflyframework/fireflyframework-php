<?php

declare(strict_types=1);

namespace Firefly\Resilience;

use Closure;
use Throwable;

/**
 * Stateless fallback: runs a callable and, if it throws an on-listed error, returns a fallback value (or, if
 * the fallback is a Closure, its result given the caught exception). Not registry-driven — it is constructed
 * with the recovery value/closure and the error list at the call site.
 */
final class Fallback
{
    /** @param  list<class-string<Throwable>>  $on */
    public function __construct(
        private readonly mixed $fallback,
        private readonly array $on = [Throwable::class],
    ) {}

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|mixed
     */
    public function call(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            if (! $this->matches($e)) {
                throw $e;
            }

            return $this->fallback instanceof Closure ? ($this->fallback)($e) : $this->fallback;
        }
    }

    private function matches(Throwable $e): bool
    {
        foreach ($this->on as $type) {
            if ($e instanceof $type) {
                return true;
            }
        }

        return false;
    }
}

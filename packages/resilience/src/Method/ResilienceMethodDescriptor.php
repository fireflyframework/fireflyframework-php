<?php

declare(strict_types=1);

namespace Firefly\Resilience\Method;

use Throwable;

/**
 * One compiled resilience rule: the class + method and the INSTANCE NAME each pattern was asked for, in the
 * order the interceptor composes them. Every field is a scalar, a null or a list of strings, so the row
 * var_exports into `proxy-plan.php` as a literal and `fromArray()` rebuilds it inside the generated proxy —
 * no reflection on the cached boot, and no registry lookup until a call actually happens.
 *
 * The descriptor deliberately holds NO policy: not a max-attempts, not a threshold, not a timeout. Those
 * live in `firefly.resilience.*` and are resolved by ResilienceRegistry at call time, so an operator raising
 * a breaker's threshold does not have to re-run `firefly:cache`. The compiled artifact records which
 * patterns guard which method; configuration records how each pattern behaves. Keeping the two apart is what
 * makes the attribute a declaration rather than a second copy of the config file.
 *
 * Every optional key is read with a null default in fromArray() for the reason SecurityMethodDescriptor
 * gives: a plan compiled before a key existed must still load.
 *
 * @phpstan-type ResilienceMethodRow array{class: string, method: string, bulkhead?: string|null, timeLimiter?: string|null, rateLimiter?: string|null, circuitBreaker?: string|null, retry?: string|null, fallbackMethod?: string|null, fallbackOn?: list<class-string<Throwable>>}
 */
final readonly class ResilienceMethodDescriptor
{
    /** @param list<class-string<Throwable>> $fallbackOn */
    public function __construct(
        public string $class,
        public string $method,
        public ?string $bulkhead = null,
        public ?string $timeLimiter = null,
        public ?string $rateLimiter = null,
        public ?string $circuitBreaker = null,
        public ?string $retry = null,
        public ?string $fallbackMethod = null,
        public array $fallbackOn = [],
    ) {}

    public function key(): string
    {
        return $this->class.'::'.$this->method;
    }

    /**
     * @return ResilienceMethodRow
     */
    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'method' => $this->method,
            'bulkhead' => $this->bulkhead,
            'timeLimiter' => $this->timeLimiter,
            'rateLimiter' => $this->rateLimiter,
            'circuitBreaker' => $this->circuitBreaker,
            'retry' => $this->retry,
            'fallbackMethod' => $this->fallbackMethod,
            'fallbackOn' => $this->fallbackOn,
        ];
    }

    /**
     * @param  ResilienceMethodRow  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['class'],
            $data['method'],
            $data['bulkhead'] ?? null,
            $data['timeLimiter'] ?? null,
            $data['rateLimiter'] ?? null,
            $data['circuitBreaker'] ?? null,
            $data['retry'] ?? null,
            $data['fallbackMethod'] ?? null,
            $data['fallbackOn'] ?? [],
        );
    }
}

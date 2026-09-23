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
 * `$fallbackAcceptsThrowable` is the one field that is not a name: it is the SHAPE of the recovery, compiled
 * for the same reason SecurityMethodDescriptor compiles `$params` rather than reading them back off the
 * method at enforcement time. The interceptor has to know whether to append the caught Throwable to the
 * original arguments, and the scan is already introspecting the recovery in order to prove its arity —
 * asking the same question again at call time would make the resilience interceptor the only one in the
 * framework that introspects on the hot path, and would contradict this class's own first paragraph. The
 * answer is a property of the compiled class, not of some wider runtime type: a row is compiled PER CONCRETE
 * CLASS (a stereotyped subclass that widens the recovery's signature compiles its own row against its own
 * copy of the method), and the proxy the interceptor is handed is the proxy of exactly that class.
 *
 * Every optional key is read with a null — or, for the booleans and lists, an empty — default in fromArray()
 * for the reason SecurityMethodDescriptor gives: a plan compiled before a key existed must still load.
 *
 * `$fallbackOn` is the one default that is not simply "empty", because for that field the empty list is not
 * an absence: it is the statement "recover NOTHING", which is what `$cause instanceof` against no entry at
 * all means to the interceptor and to Firefly\Resilience\Fallback::matches() alike. A row that names a
 * RECOVERY and reached fromArray() without the key is a plan compiled before the key existed, and the
 * attribute's own default for it is `[Throwable::class]` — recover everything — so that is what such a row
 * degrades to, rather than to a fallback that loads, composes and then never fires. A row with no recovery
 * keeps the empty list it has always had, because nothing reads it. The scan refuses an empty `on:` outright
 * (see ResilienceMethodScanner::assertFallback()), so the two values can never be confused in a plan
 * compiled by this version.
 *
 * @phpstan-type ResilienceMethodRow array{class: string, method: string, bulkhead?: string|null, timeLimiter?: string|null, rateLimiter?: string|null, circuitBreaker?: string|null, retry?: string|null, fallbackMethod?: string|null, fallbackOn?: list<class-string<Throwable>>, fallbackAcceptsThrowable?: bool}
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
        public bool $fallbackAcceptsThrowable = false,
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
            'fallbackAcceptsThrowable' => $this->fallbackAcceptsThrowable,
        ];
    }

    /**
     * @param  ResilienceMethodRow  $data
     */
    public static function fromArray(array $data): self
    {
        $fallbackMethod = $data['fallbackMethod'] ?? null;

        return new self(
            $data['class'],
            $data['method'],
            $data['bulkhead'] ?? null,
            $data['timeLimiter'] ?? null,
            $data['rateLimiter'] ?? null,
            $data['circuitBreaker'] ?? null,
            $data['retry'] ?? null,
            $fallbackMethod,
            $data['fallbackOn'] ?? ($fallbackMethod === null ? [] : [Throwable::class]),
            $data['fallbackAcceptsThrowable'] ?? false,
        );
    }
}

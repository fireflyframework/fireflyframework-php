<?php

declare(strict_types=1);

namespace Firefly\Observability\Method;

/**
 * One compiled observability rule: the class + method, and up to three normalised rows — the timer, the
 * counter and the observation — each already resolved to the values the interceptor needs (the meter name
 * with the attribute's default applied, the tag map, the flags). Every field is a scalar or an array so the
 * row var_exports as a plain literal into `proxy-plan.php` and is rebuilt inside the generated proxy by
 * `fromArray()`, which is what keeps the cached boot reflection-free.
 *
 * A method may carry all three: #[Timed] and #[Counted] together are the common pair (a latency histogram
 * and an error count), and #[Observed] beside them is legal though redundant. The interceptor applies them
 * in one pass over one clock reading, so three attributes cost one `microtime()` call, not three.
 *
 * Every optional key is read with a null default in fromArray() for the reason SecurityMethodDescriptor
 * gives: a plan compiled before a key existed must still load.
 *
 * @phpstan-type TimedRow array{name: string, tags: array<string, string>, description: string, longTask: bool}
 * @phpstan-type CountedRow array{name: string, tags: array<string, string>, failuresOnly: bool}
 * @phpstan-type ObservedRow array{name: string, contextualName: string, tags: array<string, string>}
 * @phpstan-type ObservabilityMethodRow array{class: string, method: string, timed?: TimedRow|null, counted?: CountedRow|null, observed?: ObservedRow|null}
 */
final readonly class ObservabilityMethodDescriptor
{
    /**
     * @param  TimedRow|null  $timed
     * @param  CountedRow|null  $counted
     * @param  ObservedRow|null  $observed
     */
    public function __construct(
        public string $class,
        public string $method,
        public ?array $timed = null,
        public ?array $counted = null,
        public ?array $observed = null,
    ) {}

    public function key(): string
    {
        return $this->class.'::'.$this->method;
    }

    /**
     * @return ObservabilityMethodRow
     */
    public function toArray(): array
    {
        return ['class' => $this->class, 'method' => $this->method, 'timed' => $this->timed, 'counted' => $this->counted, 'observed' => $this->observed];
    }

    /**
     * @param  ObservabilityMethodRow  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['class'], $data['method'], $data['timed'] ?? null, $data['counted'] ?? null, $data['observed'] ?? null);
    }
}

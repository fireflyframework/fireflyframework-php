<?php

declare(strict_types=1);

namespace Firefly\Observability\HttpExchanges;

/**
 * The bounded rolling record of recent HTTP exchanges — LaraFly's answer to Spring's HttpExchangeRepository, and
 * the thing an operator actually opens a dashboard to look at.
 *
 * TWO IMPLEMENTATIONS, AND THE CHOICE BETWEEN THEM IS NOT COSMETIC. This is the same trap CacheMeterRegistry was
 * introduced to escape, and it bites harder here. Under PHP-FPM every request is a fresh process: an in-memory
 * buffer is created empty, the filter appends the CURRENT request to it after the response is generated, and the
 * process dies. When a later request renders /actuator/httpexchanges it does so in a DIFFERENT process, holding a
 * DIFFERENT, empty buffer — and the endpoint's own request has not been recorded yet, because this filter records
 * on the way out. So the in-memory recorder under PHP-FPM does not show "one misleading entry"; it shows ZERO,
 * always, forever. /actuator/metrics at least looked populated while lying. This looks broken, which is arguably
 * kinder, but it is still useless.
 *
 *  - InMemoryHttpExchangeRecorder is the default and is CORRECT on a long-lived worker (Octane, RoadRunner,
 *    Swoole, a queue worker's HTTP twin), where one process serves thousands of requests and the ring genuinely
 *    fills.
 *  - CacheHttpExchangeRecorder is opt-in via `firefly.observability.httpexchanges.store` naming a cache store,
 *    and is the ONLY thing that works under PHP-FPM.
 *
 * storage() and processLocal() are on this interface for one reason: so HttpExchangesEndpoint can say, in the
 * payload, WHY the list is empty. A buffer that is silently always empty is worse than no buffer — the operator
 * concludes the application is not serving traffic. A buffer that reports `{"exchanges": [], "storage": "memory",
 * "processLocal": true}` has told them exactly what to change.
 */
interface HttpExchangeRecorder
{
    public function record(HttpExchange $exchange): void;

    /**
     * The buffered exchanges, NEWEST FIRST.
     *
     * @return list<HttpExchange>
     */
    public function exchanges(): array;

    /** How many exchanges the ring holds before the oldest is evicted. */
    public function capacity(): int;

    /**
     * Total exchanges ever recorded — monotonic, and NOT capped at capacity(). Cross-process for the
     * cache-backed recorder, process-local for the in-memory one. `recorded() - count(exchanges())` is how many
     * have been evicted, which is the number a dashboard needs to say "showing the last 100 of 41,882".
     */
    public function recorded(): int;

    /** 'memory', or 'cache:<store name>' — surfaced verbatim in the endpoint payload. */
    public function storage(): string;

    /**
     * True when the buffer lives only in the current PHP process, i.e. when a reader in another process (every
     * reader, under PHP-FPM) will see nothing this process recorded.
     */
    public function processLocal(): bool;
}

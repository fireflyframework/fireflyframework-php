<?php

declare(strict_types=1);

namespace Firefly\Observability\HttpExchanges;

use DateTimeImmutable;
use DateTimeZone;

/**
 * One recorded HTTP exchange: the flat row an operator reads off a dashboard when they want to know what the
 * application has actually been serving.
 *
 * DELIBERATELY NARROWER THAN SPRING'S. Spring's HttpExchange nests `request` (method, uri, headers, remoteAddress)
 * and `response` (status, headers) objects and reports `timeTaken` as an ISO-8601 duration ("PT0.012345S"). Two
 * choices diverge here, both on purpose:
 *
 *  1. FLAT, NOT NESTED. `request` and `response` would each hold exactly one field by default (headers are off —
 *     see below), so the nesting buys a client nothing but two extra dereferences per row. A dashboard renders
 *     these as table rows; flat rows are what a table wants.
 *  2. `durationMs` AS A NUMBER, NOT AN ISO-8601 DURATION STRING. "PT0.012345S" cannot be plotted, sorted or
 *     compared without first being parsed, and every consumer would have to ship that parser. Milliseconds as a
 *     float is the unit a latency chart already speaks.
 *
 * WHAT IS DELIBERATELY ABSENT — this is the security contract of the whole feature, not an oversight:
 *
 *  - NO request body and NO response body, ever, under any configuration. There is no flag to turn them on.
 *    Bodies carry passwords, card numbers, PII and session material; a rolling buffer of them behind an endpoint
 *    whose whole purpose is to be read by an operator is a breach waiting for its first misconfigured exposure
 *    list. Spring does not record bodies either.
 *  - NO headers by default. `$requestHeaders` is populated only when
 *    `firefly.observability.httpexchanges.include-headers` is explicitly turned on, and even then every value
 *    passes through HeaderMasker first. Authorization, Cookie and X-Api-Key are exactly the headers that make
 *    these endpoints leak credentials in every write-up of the problem.
 *  - NO remote address / no client IP. It is personal data under GDPR, it is nearly always the load balancer's
 *    address anyway, and nothing on a dashboard needs it.
 *
 * `$uri` is the ROUTE TEMPLATE ("/users/{id}") whenever the router matched one — see HttpExchangeFilter for why
 * the raw path is only ever a fallback, and what is stripped from it when it is used.
 */
final readonly class HttpExchange
{
    /**
     * @param  string  $timestamp  ISO-8601 UTC with microseconds, taken when the request STARTED (Spring's
     *                             semantic) rather than when it finished, so a row's timestamp plus its
     *                             durationMs describe the same interval.
     * @param  array<string, string>  $requestHeaders  masked and opt-in; empty unless header capture is on.
     */
    public function __construct(
        public string $timestamp,
        public string $method,
        public string $uri,
        public int $status,
        public float $durationMs,
        public ?string $correlationId,
        public array $requestHeaders = [],
    ) {}

    /**
     * Formats a `microtime(true)` float as ISO-8601 UTC with microsecond precision.
     *
     * Not `(new DateTimeImmutable('@'.$epoch))`: the `@` seconds-since-epoch constructor TRUNCATES to whole
     * seconds, so every row in a buffer filled by a burst of traffic would carry the same timestamp and the
     * newest-first ordering would look arbitrary to anyone reading it. `createFromFormat('U.u', ...)` keeps the
     * microseconds, and number_format (not (string) casting, which switches to scientific notation and drops
     * precision on large floats) produces the fixed 6-decimal input that format demands.
     */
    public static function timestampFrom(float $epochSeconds): string
    {
        $formatted = DateTimeImmutable::createFromFormat('U.u', number_format($epochSeconds, 6, '.', ''));

        if ($formatted === false) {
            // Unreachable for any finite float, but createFromFormat's signature admits false and PHPStan is
            // right to insist: falling back to "now" keeps a row in the buffer rather than dropping it.
            $formatted = new DateTimeImmutable;
        }

        return $formatted->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * The JSON row.
     *
     * `requestHeaders` is OMITTED entirely when empty rather than emitted as an empty collection. json_encode
     * renders an empty PHP array as `[]`, not `{}`, so a client deserialising the field into a map would break
     * on exactly the requests that had nothing to show — the same empty-array/empty-object hazard
     * ActuatorDispatchAction::toResponse() documents for the top-level body. Present-or-absent is a distinction
     * every JSON client already handles correctly.
     *
     * @return array{timestamp: string, method: string, uri: string, status: int, durationMs: float, correlationId: string|null, requestHeaders?: array<string, string>}
     */
    public function toArray(): array
    {
        $row = [
            'timestamp' => $this->timestamp,
            'method' => $this->method,
            'uri' => $this->uri,
            'status' => $this->status,
            'durationMs' => $this->durationMs,
            'correlationId' => $this->correlationId,
        ];

        if ($this->requestHeaders !== []) {
            $row['requestHeaders'] = $this->requestHeaders;
        }

        return $row;
    }

    /**
     * Rebuilds a row written by an earlier process (CacheHttpExchangeRecorder round-trips exchanges through the
     * cache as plain arrays, never serialized objects, so a deploy that changes this class cannot fatal on a
     * stale payload).
     *
     * Returns null — rather than throwing or fabricating defaults — for any row that is not shaped like an
     * exchange. A shared cache store is not a private data structure: another application on the same Redis, a
     * key collision, or a rolling deploy mid-schema-change can all put something else under these keys, and a
     * dashboard panel must degrade to "one fewer row" rather than to a 500 on the whole endpoint.
     *
     * @param  array<mixed>  $row
     */
    public static function fromArray(array $row): ?self
    {
        $timestamp = $row['timestamp'] ?? null;
        $method = $row['method'] ?? null;
        $uri = $row['uri'] ?? null;
        $status = $row['status'] ?? null;
        $durationMs = $row['durationMs'] ?? null;
        $correlationId = $row['correlationId'] ?? null;

        // durationMs accepts int as well as float on the way back in: a cache driver that round-trips through
        // JSON (rather than PHP serialize()) writes 12.0 and reads back the integer 12, and rejecting that row
        // would silently drop every exchange that happened to land on a whole millisecond.
        if (! is_string($timestamp) || ! is_string($method) || ! is_string($uri) || ! is_int($status) || ! is_int($durationMs) && ! is_float($durationMs)) {
            return null;
        }

        $headers = [];
        if (is_array($row['requestHeaders'] ?? null)) {
            /** @var array<mixed> $raw */
            $raw = $row['requestHeaders'];
            foreach ($raw as $name => $value) {
                if (is_string($name) && is_string($value)) {
                    $headers[$name] = $value;
                }
            }
        }

        return new self(
            $timestamp,
            $method,
            $uri,
            $status,
            (float) $durationMs,
            is_string($correlationId) ? $correlationId : null,
            $headers,
        );
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Observability\Tracing;

/**
 * W3C Trace Context (https://www.w3.org/TR/trace-context/) over a plain header map — the ONE propagation format
 * LaraFly speaks, on the inbound request, the outbound HTTP client and the EDA envelope alike, with no SDK
 * involved: the OpenTelemetry adapter converts our SpanContext at its own boundary, so propagation works
 * identically with the RecordingTracer in a test.
 *
 * extract() follows the receiver rules of the spec: a version-00 traceparent is exactly four fields; a future
 * version may carry more, and is read for its first four; `ff` is reserved and rejected; the all-zero ids are
 * "no id" and rejected. Header names are matched case-insensitively and a value may be a list (what
 * Illuminate's `$request->headers->all()` hands back) or a plain string (an envelope's header map).
 * inject() writes nothing for an invalid context — a NoOp tracer must never leave a fake traceparent behind.
 */
final class W3CTraceContextPropagator
{
    public const string TRACEPARENT = 'traceparent';

    public const string TRACESTATE = 'tracestate';

    /** @param array<array-key, mixed> $carrier */
    public function extract(array $carrier): ?SpanContext
    {
        $traceparent = $this->header($carrier, self::TRACEPARENT);
        if ($traceparent === null) {
            return null;
        }

        if (preg_match('/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})(-.*)?$/', trim($traceparent), $matches) !== 1) {
            return null;
        }

        [, $version, $traceId, $spanId, $flags] = $matches;
        $trailing = $matches[5] ?? '';

        if ($version === 'ff' || ($version === '00' && $trailing !== '')) {
            return null;
        }

        $context = new SpanContext(
            $traceId,
            $spanId,
            (hexdec($flags) & 0x01) === 1,
            $this->header($carrier, self::TRACESTATE) ?? '',
            remote: true,
        );

        return $context->isValid() ? $context : null;
    }

    /** @return array<string, string> */
    public function inject(SpanContext $context): array
    {
        if (! $context->isValid()) {
            return [];
        }

        $headers = [self::TRACEPARENT => '00-'.$context->traceId.'-'.$context->spanId.'-'.$context->traceFlags()];
        if ($context->traceState !== '') {
            $headers[self::TRACESTATE] = $context->traceState;
        }

        return $headers;
    }

    /** @param array<array-key, mixed> $carrier */
    private function header(array $carrier, string $name): ?string
    {
        foreach ($carrier as $key => $value) {
            if (strtolower((string) $key) !== $name) {
                continue;
            }
            if (is_array($value)) {
                $value = $value[0] ?? null;
            }

            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        return null;
    }
}

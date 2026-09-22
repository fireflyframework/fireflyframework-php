<?php

declare(strict_types=1);

namespace Firefly\Observability\Tracing;

/**
 * The role a span plays in a trace — OpenTelemetry's five kinds, verbatim, because a backend draws the request
 * graph from them: a SERVER span is the receiving side of another service's CLIENT span, and a CONSUMER span is
 * the receiving side of a PRODUCER span, possibly minutes later on a different worker. INTERNAL is everything
 * that never crosses a process boundary (a command handler, a query).
 */
enum SpanKind: string
{
    case Internal = 'INTERNAL';
    case Server = 'SERVER';
    case Client = 'CLIENT';
    case Producer = 'PRODUCER';
    case Consumer = 'CONSUMER';
}

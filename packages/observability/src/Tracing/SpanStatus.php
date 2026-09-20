<?php

declare(strict_types=1);

namespace Firefly\Observability\Tracing;

/**
 * OpenTelemetry's three-valued span status. Unset is the default and means "nothing went wrong that the
 * instrumentation knows of"; instrumentation sets Error for a 5xx, a thrown handler, a rejected outbound call —
 * and only an application ever sets Ok explicitly.
 */
enum SpanStatus: string
{
    case Unset = 'UNSET';
    case Ok = 'OK';
    case Error = 'ERROR';
}

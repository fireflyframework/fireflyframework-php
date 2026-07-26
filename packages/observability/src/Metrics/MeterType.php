<?php

declare(strict_types=1);

namespace Firefly\Observability\Metrics;

/** The three meter kinds. Timer exposes as a Prometheus summary (count + sum); percentiles are deferred to SP-7. */
enum MeterType: string
{
    case Counter = 'counter';
    case Gauge = 'gauge';
    case Timer = 'timer';
}

<?php

declare(strict_types=1);

namespace Firefly\Observability\Metrics;

/** The three meter kinds. A Timer exposes as a Prometheus summary (count + sum), or as a histogram when its name has distribution buckets configured. */
enum MeterType: string
{
    case Counter = 'counter';
    case Gauge = 'gauge';
    case Timer = 'timer';
}

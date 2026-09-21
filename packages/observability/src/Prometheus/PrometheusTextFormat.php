<?php

declare(strict_types=1);

namespace Firefly\Observability\Prometheus;

use Firefly\Observability\Metrics\Counter;
use Firefly\Observability\Metrics\Gauge;
use Firefly\Observability\Metrics\Meter;
use Firefly\Observability\Metrics\MeterType;
use Firefly\Observability\Metrics\Timer;

/**
 * A pure-PHP Prometheus text-exposition (format version 0.0.4) — no ext-prometheus, no OTel. Groups meters into
 * families (sanitised name + type), emits one `# HELP`/`# TYPE` per family, then the samples. Label values and HELP
 * text are escaped per the 0.0.4 spec; names are sanitised to [a-zA-Z_:][a-zA-Z0-9_:]*. Timers expose as summaries
 * (`_count` + `_sum`), or as histograms (`_bucket{le}` + `_count` + `_sum`) when their name has distribution
 * buckets configured.
 */
final class PrometheusTextFormat
{
    /**
     * @param  list<Meter>  $meters
     */
    public function render(array $meters): string
    {
        /** @var array<string, array{name: string, type: MeterType, meters: list<Meter>}> $families */
        $families = [];
        foreach ($meters as $meter) {
            $name = $this->sanitizeName($meter->name());
            $key = $meter->type()->value.'|'.$name;
            if (! isset($families[$key])) {
                $families[$key] = ['name' => $name, 'type' => $meter->type(), 'meters' => []];
            }
            $families[$key]['meters'][] = $meter;
        }

        $lines = [];
        foreach ($families as $family) {
            $name = $family['name'];
            $lines[] = '# HELP '.$name.' '.$this->escapeHelp($name.' ('.$family['type']->value.')');
            $lines[] = '# TYPE '.$name.' '.$this->promType($family['type'], $family['meters'][0]);
            foreach ($family['meters'] as $meter) {
                foreach ($this->samples($name, $meter) as $sample) {
                    $lines[] = $sample;
                }
            }
        }

        return $lines === [] ? "\n" : implode("\n", $lines)."\n";
    }

    /**
     * @return list<string>
     */
    private function samples(string $name, Meter $meter): array
    {
        $labels = $this->labels($meter->tags());

        return match (true) {
            $meter instanceof Counter => [$name.$labels.' '.$this->value($meter->count())],
            $meter instanceof Gauge => [$name.$labels.' '.$this->value($meter->value())],
            $meter instanceof Timer => $meter->hasBuckets()
                ? $this->histogram($name, $meter, $labels)
                : [
                    $name.'_count'.$labels.' '.$this->value((float) $meter->count()),
                    $name.'_sum'.$labels.' '.$this->value($meter->totalTimeSeconds()),
                ],
            default => [],
        };
    }

    /**
     * The histogram family: cumulative `_bucket` samples with `le` as the LAST label (the order every
     * Prometheus client emits, and what a human scanning a scrape expects), the implicit `+Inf` bucket equal
     * to the count, then `_count` and `_sum` — the same two lines the summary has, so a dashboard built on
     * `rate(x_sum[5m]) / rate(x_count[5m])` keeps working the day buckets are switched on.
     *
     * @return list<string>
     */
    private function histogram(string $name, Timer $timer, string $labels): array
    {
        $lines = [];
        foreach ($timer->bucketCounts() as $bucket) {
            $lines[] = $name.'_bucket'.$this->labelsWithLe($labels, $this->value($bucket['le'])).' '.$this->value((float) $bucket['count']);
        }
        $lines[] = $name.'_bucket'.$this->labelsWithLe($labels, '+Inf').' '.$this->value((float) $timer->count());
        $lines[] = $name.'_count'.$labels.' '.$this->value((float) $timer->count());
        $lines[] = $name.'_sum'.$labels.' '.$this->value($timer->totalTimeSeconds());

        return $lines;
    }

    /** Appends `le="<bound>"` to an already-rendered label block (`{a="b"}` or ``). */
    private function labelsWithLe(string $labels, string $le): string
    {
        $le = 'le="'.$le.'"';

        return $labels === '' ? '{'.$le.'}' : substr($labels, 0, -1).','.$le.'}';
    }

    /**
     * @param  array<string, string>  $tags
     */
    private function labels(array $tags): string
    {
        if ($tags === []) {
            return '';
        }

        ksort($tags);
        $parts = [];
        foreach ($tags as $key => $val) {
            $parts[] = $this->sanitizeLabelName((string) $key).'="'.$this->escapeLabelValue((string) $val).'"';
        }

        return '{'.implode(',', $parts).'}';
    }

    private function value(float $value): string
    {
        if (is_nan($value)) {
            return 'NaN';
        }
        if (is_infinite($value)) {
            return $value > 0 ? '+Inf' : '-Inf';
        }
        if ($value === floor($value) && abs($value) < 1.0e15) {
            return (string) (int) $value;
        }

        // number_format() (unlike sprintf('%f')) is locale-INDEPENDENT here: the decimal point and thousands
        // separator are passed explicitly as arguments, so LC_NUMERIC (e.g. a comma-decimal locale such as
        // de_DE) cannot leak a ',' into the exposition and produce unscrapeable Prometheus output.
        return rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');
    }

    private function promType(MeterType $type, Meter $first): string
    {
        return match ($type) {
            MeterType::Counter => 'counter',
            MeterType::Gauge => 'gauge',
            MeterType::Timer => $first instanceof Timer && $first->hasBuckets() ? 'histogram' : 'summary',
        };
    }

    private function sanitizeName(string $name): string
    {
        $name = preg_replace('/[^a-zA-Z0-9_:]/', '_', $name) ?? $name;
        if ($name === '' || preg_match('/^[a-zA-Z_:]/', $name) !== 1) {
            $name = '_'.$name;
        }

        return $name;
    }

    /**
     * Same idiom as {@see sanitizeName()} but for LABEL names: per the Prometheus 0.0.4 grammar
     * `label_name ::= [a-zA-Z_][a-zA-Z0-9_]*`, `:` is reserved for metric names and is NOT valid in a label name.
     */
    private function sanitizeLabelName(string $name): string
    {
        $name = preg_replace('/[^a-zA-Z0-9_]/', '_', $name) ?? $name;
        if ($name === '' || preg_match('/^[a-zA-Z_]/', $name) !== 1) {
            $name = '_'.$name;
        }

        return $name;
    }

    private function escapeLabelValue(string $value): string
    {
        return str_replace(['\\', "\n", '"'], ['\\\\', '\\n', '\\"'], $value);
    }

    private function escapeHelp(string $value): string
    {
        return str_replace(['\\', "\n"], ['\\\\', '\\n'], $value);
    }
}

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
 * (`_count` + `_sum`); histogram buckets/percentiles are deferred to SP-7.
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
            $lines[] = '# TYPE '.$name.' '.$this->promType($family['type']);
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
            $meter instanceof Timer => [
                $name.'_count'.$labels.' '.$this->value((float) $meter->count()),
                $name.'_sum'.$labels.' '.$this->value($meter->totalTimeSeconds()),
            ],
            default => [],
        };
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
            $parts[] = $this->sanitizeName((string) $key).'="'.$this->escapeLabelValue((string) $val).'"';
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

        return rtrim(rtrim(sprintf('%.10f', $value), '0'), '.');
    }

    private function promType(MeterType $type): string
    {
        return match ($type) {
            MeterType::Counter => 'counter',
            MeterType::Gauge => 'gauge',
            MeterType::Timer => 'summary',
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

    private function escapeLabelValue(string $value): string
    {
        return str_replace(['\\', "\n", '"'], ['\\\\', '\\n', '\\"'], $value);
    }

    private function escapeHelp(string $value): string
    {
        return str_replace(['\\', "\n"], ['\\\\', '\\n'], $value);
    }
}

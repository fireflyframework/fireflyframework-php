<?php

declare(strict_types=1);

use Firefly\Observability\Metrics\SimpleMeterRegistry;
use Firefly\Observability\Prometheus\PrometheusTextFormat;

it('emits HELP/TYPE and a labelled counter sample', function () {
    $registry = new SimpleMeterRegistry;
    $registry->counter('http_server_requests_total', ['method' => 'GET', 'status' => '200'])->increment(3.0);

    $text = (new PrometheusTextFormat)->render($registry->meters());

    expect($text)->toContain('# HELP http_server_requests_total')
        ->toContain('# TYPE http_server_requests_total counter')
        ->toContain('http_server_requests_total{method="GET",status="200"} 3')
        ->and(str_ends_with($text, "\n"))->toBeTrue();
});

it('escapes label values and sanitises names', function () {
    $registry = new SimpleMeterRegistry;
    $registry->counter('weird metric', ['q' => 'a"b\\c'])->increment();

    $text = (new PrometheusTextFormat)->render($registry->meters());

    expect($text)->toContain('weird_metric{q="a\\"b\\\\c"} 1')
        ->and($text)->toContain('# TYPE weird_metric counter');
});

it('sanitises label names to exclude the metric-name-only colon (FIX 2)', function () {
    // Per the Prometheus 0.0.4 grammar, label_name ::= [a-zA-Z_][a-zA-Z0-9_]* — ':' is reserved for METRIC
    // names and must never survive into a label name, even though sanitizeName() (used for the metric name
    // itself) does allow it.
    $registry = new SimpleMeterRegistry;
    $registry->counter('colon_label_metric', ['q:x' => 'v'])->increment();

    $text = (new PrometheusTextFormat)->render($registry->meters());

    expect($text)->toContain('colon_label_metric{q_x="v"} 1')
        ->not->toContain('q:x=');
});

it('emits a timer as a summary with _count and _sum', function () {
    $registry = new SimpleMeterRegistry;
    $timer = $registry->timer('cqrs_commands_seconds', ['type' => 'OpenAccount']);
    $timer->record(0.25);
    $timer->record(0.75);

    $text = (new PrometheusTextFormat)->render($registry->meters());

    expect($text)->toContain('# TYPE cqrs_commands_seconds summary')
        ->toContain('cqrs_commands_seconds_count{type="OpenAccount"} 2')
        ->toContain('cqrs_commands_seconds_sum{type="OpenAccount"} 1');
});

it('renders a supplier gauge and special float values', function () {
    $registry = new SimpleMeterRegistry;
    $registry->gauge('mem_bytes', [], fn (): float => 1024.0);
    $registry->gauge('ratio', [], fn (): float => INF);

    $text = (new PrometheusTextFormat)->render($registry->meters());

    expect($text)->toContain('# TYPE mem_bytes gauge')
        ->toContain('mem_bytes 1024')
        ->toContain('ratio +Inf');
});

it('renders fractional values with a period regardless of LC_NUMERIC (locale regression)', function () {
    $priorLocale = setlocale(LC_ALL, '0');
    $localeSet = setlocale(LC_ALL, 'de_DE.UTF-8', 'de_DE', 'de_DE@euro', 'German_Germany.1252');

    try {
        // FIX 1 regression: the OLD implementation used sprintf('%.10f', $value), which is LC_NUMERIC-dependent
        // and renders '1234,5' (comma) under a comma-decimal locale such as de_DE — unscrapeable by Prometheus.
        // The fix (number_format($value, 10, '.', '')) is locale-independent, so this must always be '1234.5'.
        if ($localeSet === false) {
            // No de_DE-family locale is installed in this environment; we can't force the LC_NUMERIC condition
            // that used to break sprintf('%f'), but number_format()'s contract (explicit '.' separator, never
            // locale-affected) means the assertion below is still a valid, if slightly weaker, regression check.
        }

        $registry = new SimpleMeterRegistry;
        $registry->gauge('locale_gauge', [], fn (): float => 1234.5);
        $timer = $registry->timer('locale_timer_seconds', []);
        $timer->record(1234.5);

        $text = (new PrometheusTextFormat)->render($registry->meters());

        expect($text)->toContain('locale_gauge 1234.5')
            ->toContain('locale_timer_seconds_sum 1234.5')
            ->not->toContain('1234,5');
    } finally {
        if ($priorLocale !== false) {
            setlocale(LC_ALL, $priorLocale);
        }
    }
});

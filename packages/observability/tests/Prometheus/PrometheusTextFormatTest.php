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

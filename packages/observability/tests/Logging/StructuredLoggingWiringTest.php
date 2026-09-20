<?php

declare(strict_types=1);

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Observability\ObservabilityServiceProvider;
use Firefly\Observability\ObservabilityWiringProvider;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

/**
 * The whole path over a real Log:: write: config → the wiring provider's afterResolving('log') hook → the
 * channel's real Monolog logger gets the processors and its handler gets the formatter → the handler's
 * `formatted` string is the JSON line an aggregator would ingest. Same channel/handler shape as
 * CorrelationIdLogProcessorTest (a bind() closure so LogManager wires the SAME TestHandler instance).
 *
 * @return array<string, mixed>
 */
function structuredRecord(string $format): array
{
    $handler = new TestHandler;
    app()->bind(TestHandler::class, fn () => $handler);

    config()->set('app.name', 'ledger');
    config()->set('app.env', 'testing');
    // `name` is what LogManager hands Monolog as the logger's channel (ParsesLogConfiguration::parseChannel:
    // `$config['name'] ?? app.env`) — the config KEY `structured_test` never reaches the record on its own.
    config()->set('logging.channels.structured_test', ['driver' => 'monolog', 'handler' => TestHandler::class, 'name' => 'structured_test']);
    config()->set('logging.default', 'structured_test');
    config()->set('firefly.logging.structured.format', $format);

    app()->register(FireflyAutoConfigureServiceProvider::class);
    app()->register(ObservabilityServiceProvider::class);
    app()->register(ObservabilityWiringProvider::class);

    Context::add('firefly.trace_id', '4bf92f3577b34da6a3ce929d0e0e4736');
    Context::add('firefly.span_id', '00f067aa0ba902b7');
    Context::add('firefly.correlation_id', 'corr-7');
    Context::add('firefly.request_id', 'req-7');

    Log::warning('disk almost full', ['free' => '3%']);

    $records = array_values(array_filter($handler->getRecords(), static fn (LogRecord $r): bool => $r->message === 'disk almost full'));
    expect($records)->toHaveCount(1);

    $formatted = $records[0]->formatted;
    expect($formatted)->toBeString();

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(is_string($formatted) ? $formatted : '{}', true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

it('writes a JSON line with the ids, the app and the context when format=json', function () {
    $line = structuredRecord('json');

    expect($line['message'])->toBe('disk almost full')
        ->and($line['level_name'])->toBe('WARNING')
        ->and($line['channel'])->toBe('structured_test')
        ->and($line['context'])->toBe(['free' => '3%'])
        ->and($line['extra'])->toMatchArray([
            'trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736',
            'span_id' => '00f067aa0ba902b7',
            'correlation_id' => 'corr-7',
            'request_id' => 'req-7',
            'service_name' => 'ledger',
            'service_environment' => 'testing',
        ]);
});

it('writes an ECS line when format=ecs', function () {
    $line = structuredRecord('ecs');

    expect($line['log.level'])->toBe('warning')
        ->and($line['service'])->toBe(['name' => 'ledger', 'environment' => 'testing'])
        ->and($line['trace'])->toBe(['id' => '4bf92f3577b34da6a3ce929d0e0e4736'])
        ->and($line['span'])->toBe(['id' => '00f067aa0ba902b7'])
        ->and($line['labels'])->toBe(['correlation_id' => 'corr-7', 'request_id' => 'req-7'])
        ->and($line['context'])->toBe(['free' => '3%']);
});

it('writes a Logstash line with the ids under fields when format=logstash', function () {
    $line = structuredRecord('logstash');

    expect($line['@version'])->toBe(1)
        ->and($line['level'])->toBe('WARNING')
        ->and($line['type'])->toBe('ledger')
        ->and($line['fields'])->toMatchArray(['trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736', 'service_name' => 'ledger'])
        ->and($line['context'])->toBe(['free' => '3%']);
});

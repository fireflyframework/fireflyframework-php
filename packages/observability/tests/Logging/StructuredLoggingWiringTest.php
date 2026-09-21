<?php

declare(strict_types=1);

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
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
 * This is the EARLY path only: the providers are registered after Testbench has booted, so the boot pipeline's
 * WiringPasses phase is already complete and LogChannelWiringPass never runs here. The boot-time guarantee —
 * a refusal that survives Laravel swallowing the hook's, and a LogManager resolved before the provider
 * registered — is Boot/LogChannelWiringPassTest's, over a real Application::boot().
 *
 * @param  array<string, mixed>  $overrides  config keys set on top of the defaults (more logging.channels.*, another
 *                                           logging.default, an explicit firefly.logging.structured.channels)
 * @return array<string, mixed>
 */
function structuredRecord(string $format, array $overrides = []): array
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
    foreach ($overrides as $key => $value) {
        config()->set($key, $value);
    }

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

it('formats the members of an ignore_exceptions stack, which Laravel wraps in a WhatFailureGroupHandler', function () {
    // LogManager::createStackDriver puts the members' handlers inside ONE WhatFailureGroupHandler when
    // `ignore_exceptions` is on; that group is not a FormattableHandlerInterface, so a formatter set only on
    // handlers that are would never reach the TestHandler and this line would still be LineFormatter text.
    $line = structuredRecord('json', [
        'logging.channels.structured_stack' => ['driver' => 'stack', 'channels' => ['structured_test'], 'ignore_exceptions' => true, 'name' => 'structured_stack'],
        'logging.default' => 'structured_stack',
    ]);

    expect($line['message'])->toBe('disk almost full')
        ->and($line['channel'])->toBe('structured_stack')
        ->and($line['context'])->toBe(['free' => '3%'])
        ->and($line['extra'])->toMatchArray([
            'trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736',
            'correlation_id' => 'corr-7',
            'request_id' => 'req-7',
            'service_name' => 'ledger',
        ]);
});

it('keeps the ids and the formatter on the default channel when channels names it explicitly', function () {
    $line = structuredRecord('json', ['firefly.logging.structured.channels' => ['structured_test']]);

    expect($line['channel'])->toBe('structured_test')
        ->and($line['extra'])->toMatchArray([
            'trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736',
            'span_id' => '00f067aa0ba902b7',
            'correlation_id' => 'corr-7',
            'request_id' => 'req-7',
            'service_name' => 'ledger',
        ]);
});

it('refuses the first log resolution when channels names a channel logging.channels does not define', function () {
    // The alternative is silence: LogManager::channel('reall') catches its own InvalidArgumentException and
    // returns a throw-away emergency logger, so the processors and the formatter would land on an object nobody
    // writes to while the real default channel kept plain text without a single id. This is the hook's refusal,
    // the one a caller CAN see when nothing swallows it; the one the application is guaranteed to see is the
    // pass's, from boot (Boot/LogChannelWiringPassTest).
    config()->set('logging.channels.structured_test', ['driver' => 'monolog', 'handler' => TestHandler::class]);
    config()->set('logging.default', 'structured_test');
    config()->set('firefly.logging.structured.format', 'json');
    config()->set('firefly.logging.structured.channels', ['reall']);

    app()->register(FireflyAutoConfigureServiceProvider::class);
    app()->register(ObservabilityServiceProvider::class);
    app()->register(ObservabilityWiringProvider::class);

    expect(fn () => Log::warning('never formatted'))
        ->toThrow(ConfigurationException::class, "Unknown log channel 'reall' (firefly.logging.structured.channels)");
});

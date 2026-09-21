<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Observability\Tracing\OpenTelemetry\TraceExporterFactory;
use Illuminate\Config\Repository as ConfigRepository;
use OpenTelemetry\Contrib\Otlp\SpanExporter as OtlpSpanExporter;
use OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporter;
use PHPUnit\Framework\Assert;

/** @param array<string, mixed> $tracing */
function tracingConfig(array $tracing): Config
{
    return new Config(new ConfigRepository(['firefly' => ['observability' => ['tracing' => $tracing]]]));
}

it('builds no exporter for none, which is the default', function () {
    expect(TraceExporterFactory::fromConfig(tracingConfig([])))->toBeNull()
        ->and(TraceExporterFactory::fromConfig(tracingConfig(['exporter' => 'none'])))->toBeNull();
});

it('builds the console exporter', function () {
    expect(TraceExporterFactory::fromConfig(tracingConfig(['exporter' => 'console'])))->toBeInstanceOf(ConsoleSpanExporter::class);
});

it('builds an OTLP exporter over http/protobuf by default and over http/json when asked', function () {
    expect(TraceExporterFactory::fromConfig(tracingConfig(['exporter' => 'otlp'])))->toBeInstanceOf(OtlpSpanExporter::class)
        ->and(TraceExporterFactory::fromConfig(tracingConfig([
            'exporter' => 'otlp',
            'otlp' => ['endpoint' => 'https://otel.example.test', 'protocol' => 'http/json', 'headers' => 'authorization=Bearer x,x-tenant=acme'],
        ])))->toBeInstanceOf(OtlpSpanExporter::class);
});

it('parses OTLP headers from the OTEL_EXPORTER_OTLP_HEADERS shape or a map', function () {
    expect(TraceExporterFactory::headers('authorization=Bearer x, x-tenant=acme'))->toBe(['authorization' => 'Bearer x', 'x-tenant' => 'acme'])
        ->and(TraceExporterFactory::headers(['x-tenant' => 'acme']))->toBe(['x-tenant' => 'acme'])
        ->and(TraceExporterFactory::headers(''))->toBe([])
        ->and(TraceExporterFactory::headers(null))->toBe([]);
});

it('percent-decodes header values in the string shape exactly as the SDK decodes OTEL_EXPORTER_OTLP_HEADERS', function () {
    // Grafana Cloud's documented value is `Authorization=Basic%20<b64>`: the SDK rawurldecodes it, so the
    // same string must reach the collector as `Basic <b64>` here too, or the collector answers 401 at the
    // first flush with nothing said at boot.
    expect(TraceExporterFactory::headers('authorization=Basic%20abc'))->toBe(['authorization' => 'Basic abc'])
        ->and(TraceExporterFactory::headers('x-tenant=a%2Cb, x-scope=x%3Dy'))->toBe(['x-tenant' => 'a,b', 'x-scope' => 'x=y']);
});

it('leaves a map\'s values untouched, since a PHP array has no delimiter to escape', function () {
    expect(TraceExporterFactory::headers(['authorization' => 'Basic%20abc']))->toBe(['authorization' => 'Basic%20abc']);
});

it('refuses a header pair without = at boot, naming the key and the pair but never the credential', function () {
    expect(fn () => TraceExporterFactory::headers('x-honeycomb-team hcaik_SECRET'))
        ->toThrow(ConfigurationException::class, 'firefly.observability.tracing.otlp.headers')
        ->and(fn () => TraceExporterFactory::headers('authorization=Bearer x, x-honeycomb-team: hcaik_SECRET'))
        ->toThrow(ConfigurationException::class, "pair 2 ('x-honeycomb-team:")
        ->and(fn () => TraceExporterFactory::headers('=hcaik_SECRET'))
        ->toThrow(ConfigurationException::class, 'firefly.observability.tracing.otlp.headers');

    foreach (['x-honeycomb-team hcaik_SECRET' => "pair 1 ('x-honeycomb-team…')", '=hcaik_SECRET' => "pair 1 ('…')"] as $raw => $named) {
        try {
            TraceExporterFactory::headers($raw);
            Assert::fail('expected a ConfigurationException');
        } catch (ConfigurationException $e) {
            expect($e->getMessage())->not->toContain('hcaik_SECRET')->toContain($named);
        }
    }
});

it('refuses a malformed header pair from config through the otlp arm, before any exporter is built', function () {
    expect(fn () => TraceExporterFactory::fromConfig(tracingConfig([
        'exporter' => 'otlp',
        'otlp' => ['headers' => 'x-honeycomb-team hcaik_SECRET'],
    ])))->toThrow(ConfigurationException::class, 'firefly.observability.tracing.otlp.headers');
});

it('fails fast on an unknown exporter, an unknown protocol, and grpc without the transport package', function () {
    expect(fn () => TraceExporterFactory::fromConfig(tracingConfig(['exporter' => 'jaeger'])))
        ->toThrow(ConfigurationException::class, "Unknown tracing exporter 'jaeger'")
        ->and(fn () => TraceExporterFactory::fromConfig(tracingConfig(['exporter' => 'otlp', 'otlp' => ['protocol' => 'thrift']])))
        ->toThrow(ConfigurationException::class, "Unknown OTLP protocol 'thrift'")
        ->and(fn () => TraceExporterFactory::fromConfig(tracingConfig(['exporter' => 'otlp', 'otlp' => ['protocol' => 'grpc']])))
        ->toThrow(ConfigurationException::class, 'open-telemetry/transport-grpc');
})->skip(class_exists('OpenTelemetry\Contrib\Grpc\GrpcTransportFactory'), 'transport-grpc is installed here, so the grpc arm builds');

<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Observability\Tracing\OpenTelemetry\TraceExporterFactory;
use Illuminate\Config\Repository as ConfigRepository;
use OpenTelemetry\Contrib\Otlp\SpanExporter as OtlpSpanExporter;
use OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporter;

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

it('fails fast on an unknown exporter, an unknown protocol, and grpc without the transport package', function () {
    expect(fn () => TraceExporterFactory::fromConfig(tracingConfig(['exporter' => 'jaeger'])))
        ->toThrow(ConfigurationException::class, "Unknown tracing exporter 'jaeger'")
        ->and(fn () => TraceExporterFactory::fromConfig(tracingConfig(['exporter' => 'otlp', 'otlp' => ['protocol' => 'thrift']])))
        ->toThrow(ConfigurationException::class, "Unknown OTLP protocol 'thrift'")
        ->and(fn () => TraceExporterFactory::fromConfig(tracingConfig(['exporter' => 'otlp', 'otlp' => ['protocol' => 'grpc']])))
        ->toThrow(ConfigurationException::class, 'open-telemetry/transport-grpc');
})->skip(class_exists('OpenTelemetry\Contrib\Grpc\GrpcTransportFactory'), 'transport-grpc is installed here, so the grpc arm builds');

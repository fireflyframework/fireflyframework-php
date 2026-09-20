<?php

declare(strict_types=1);

namespace Firefly\Observability\Tracing\OpenTelemetry;

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use OpenTelemetry\API\Signals;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\HttpEndpointResolver;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\OtlpUtil;
use OpenTelemetry\Contrib\Otlp\Protocols;
use OpenTelemetry\Contrib\Otlp\SpanExporter as OtlpSpanExporter;
use OpenTelemetry\SDK\Common\Export\Stream\StreamTransportFactory;
use OpenTelemetry\SDK\Common\Export\TransportFactoryInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporter;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use UnexpectedValueException;

/**
 * Builds the span exporter `firefly.observability.tracing.exporter` names — the three Spring Boot ships out of
 * the box, minus Zipkin (OTLP is what every collector and vendor accept today):
 *
 *   none    — no exporter and no span processor. Spans are still recorded (ids for logs and propagation),
 *             nothing leaves the process. The default.
 *   console — one pretty-printed JSON document per span on stdout, for a developer watching a terminal.
 *   otlp    — OTLP over http/protobuf (the default and the one every collector speaks), http/json, or grpc.
 *             The generic endpoint gets `/v1/traces` appended the way the OTel spec says a generic
 *             OTEL_EXPORTER_OTLP_ENDPOINT does. grpc needs open-telemetry/transport-grpc AND ext-grpc, and is
 *             refused with the package name when the class is not loadable rather than failing inside the
 *             SDK's registry.
 *
 * Every misconfiguration is a ConfigurationException naming the key, at boot, not a warning at first span.
 */
final class TraceExporterFactory
{
    private const string GRPC_TRANSPORT_FACTORY = 'OpenTelemetry\Contrib\Grpc\GrpcTransportFactory';

    public static function fromConfig(Config $config): ?SpanExporterInterface
    {
        $exporter = $config->string('firefly.observability.tracing.exporter', 'none');

        return match ($exporter) {
            'none' => null,
            'console' => new ConsoleSpanExporter((new StreamTransportFactory)->create('php://stdout', 'application/json')),
            'otlp' => self::otlp($config),
            default => throw new ConfigurationException(
                "Unknown tracing exporter '{$exporter}' (firefly.observability.tracing.exporter); use none, console or otlp.",
            ),
        };
    }

    /**
     * `k=v,k2=v2` (the OTEL_EXPORTER_OTLP_HEADERS shape, so one value works in both places) or a plain map.
     *
     * @return array<string, string>
     */
    public static function headers(mixed $raw): array
    {
        $headers = [];

        if (is_array($raw)) {
            foreach ($raw as $name => $value) {
                if (is_string($name) && $name !== '' && is_scalar($value)) {
                    $headers[$name] = (string) $value;
                }
            }

            return $headers;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        foreach (explode(',', $raw) as $pair) {
            $pair = trim($pair);
            $eq = strpos($pair, '=');
            if ($pair === '' || $eq === false || $eq === 0) {
                continue;
            }
            $headers[trim(substr($pair, 0, $eq))] = trim(substr($pair, $eq + 1));
        }

        return $headers;
    }

    private static function otlp(Config $config): SpanExporterInterface
    {
        $protocol = $config->string('firefly.observability.tracing.otlp.protocol', Protocols::HTTP_PROTOBUF);
        $endpoint = $config->string('firefly.observability.tracing.otlp.endpoint', 'http://localhost:4318');
        $headers = self::headers($config->get('firefly.observability.tracing.otlp.headers', ''));

        try {
            Protocols::validate($protocol);
        } catch (UnexpectedValueException) {
            throw new ConfigurationException(
                "Unknown OTLP protocol '{$protocol}' (firefly.observability.tracing.otlp.protocol); use http/protobuf, http/json or grpc.",
            );
        }

        if ($protocol === Protocols::GRPC) {
            if (! class_exists(self::GRPC_TRANSPORT_FACTORY)) {
                throw new ConfigurationException(
                    'firefly.observability.tracing.otlp.protocol=grpc needs the open-telemetry/transport-grpc package (and ext-grpc): '
                    .'composer require open-telemetry/transport-grpc, or use http/protobuf.',
                );
            }

            $class = self::GRPC_TRANSPORT_FACTORY;
            /** @var TransportFactoryInterface $factory */
            $factory = new $class;

            return new OtlpSpanExporter($factory->create($endpoint.OtlpUtil::method(Signals::TRACE), ContentTypes::PROTOBUF, $headers));
        }

        $transport = (new OtlpHttpTransportFactory)->create(
            HttpEndpointResolver::create()->resolveToString($endpoint, Signals::TRACE),
            Protocols::contentType($protocol),
            $headers,
        );

        return new OtlpSpanExporter($transport);
    }
}

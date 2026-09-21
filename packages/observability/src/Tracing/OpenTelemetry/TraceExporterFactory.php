<?php

declare(strict_types=1);

namespace Firefly\Observability\Tracing\OpenTelemetry;

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use InvalidArgumentException;
use OpenTelemetry\API\Signals;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\HttpEndpointResolver;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\OtlpUtil;
use OpenTelemetry\Contrib\Otlp\Protocols;
use OpenTelemetry\Contrib\Otlp\SpanExporter as OtlpSpanExporter;
use OpenTelemetry\SDK\Common\Configuration\Parser\MapParser;
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
     * The string shape is handed to the SDK's own MapParser and then percent-decoded, which is exactly what
     * OtlpUtil::getHeaders() does with the environment variable: the OTel exporter spec encodes a value the
     * way W3C Baggage does, so a vendor's documented `Authorization=Basic%20<b64>` must reach the collector
     * as `Basic <b64>` here too, or the collector answers 401 at the first flush with nothing said at boot.
     * A pair without `=` is the SDK's InvalidArgumentException, re-thrown as a ConfigurationException that
     * names the key and the pair's POSITION plus its leading token — never the rest of the pair, because
     * the likeliest mistake (`x-honeycomb-team hcaik_…`, a space or a colon instead of `=`) has the
     * credential right there and a boot exception's message is logged.
     *
     * The map shape is taken as written: a PHP array has no delimiter to escape, so its values are neither
     * parsed nor decoded, and a non-scalar leaf is simply not a header.
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

        try {
            $parsed = MapParser::parse($raw);
        } catch (InvalidArgumentException $e) {
            throw new ConfigurationException(
                'firefly.observability.tracing.otlp.headers has a pair without `=`: '
                .self::describeMalformedPair($raw, static fn (string $pair): bool => ! str_contains($pair, '='))
                .'; use name=value,name2=value2 (values percent-encoded, as in OTEL_EXPORTER_OTLP_HEADERS).',
                previous: $e,
            );
        }

        foreach ($parsed as $name => $value) {
            $name = (string) $name;
            if ($name === '') {
                throw new ConfigurationException(
                    'firefly.observability.tracing.otlp.headers has a pair with an empty header name: '
                    .self::describeMalformedPair($raw, static fn (string $pair): bool => trim((string) strstr($pair, '=', true)) === '')
                    .'; use name=value,name2=value2.',
                );
            }

            $headers[$name] = rawurldecode(is_scalar($value) ? (string) $value : '');
        }

        return $headers;
    }

    /**
     * `pair N ('leading-token…')` for the first comma-separated pair $malformed accepts. Only the text up to
     * the first whitespace or `=` is quoted, so a credential typed after a space, a colon or a bare `=`
     * stays out of the message and out of the log the boot exception lands in.
     *
     * @param  callable(string): bool  $malformed
     */
    private static function describeMalformedPair(string $raw, callable $malformed): string
    {
        foreach (explode(',', $raw) as $index => $pair) {
            $pair = trim($pair);
            if (! $malformed($pair)) {
                continue;
            }

            $token = (string) preg_replace('/[\\s=].*$/su', '', $pair);
            $shown = mb_substr($token, 0, 32).($token !== $pair || mb_strlen($token) > 32 ? '…' : '');

            return sprintf("pair %d ('%s')", $index + 1, $shown);
        }

        return 'pair ?';
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

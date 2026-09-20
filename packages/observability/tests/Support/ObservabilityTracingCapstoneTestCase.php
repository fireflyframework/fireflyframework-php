<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Support;

/**
 * The tracing-ENABLED sibling of ObservabilityCapstoneTestCase, configured the way an application pointed at
 * a tracing vendor is: `firefly.observability.tracing.enabled` on (read by OpenTelemetryAutoConfiguration's
 * #[ConditionalOnProperty] at BOOT time, so its own boot — the same reason ObservabilityDisabledCapstoneTestCase
 * exists), and the vendor's credential in `tracing.otlp.headers`, exactly where the skeleton's config
 * documents it should go. The exporter stays `none` so no span leaves the test process; the credential is
 * in the configuration tree regardless, which is the whole point — /env renders configuration, not the
 * exporter, and a value that is configured is a value that can leak.
 *
 * `env` is added to the exposure list because this capstone's subject is what the actuator SHOWS of the
 * tracing configuration, not only what the tracer does with it.
 */
class ObservabilityTracingCapstoneTestCase extends ObservabilityCapstoneTestCase
{
    /** A Honeycomb-shaped credential pair in the OTEL_EXPORTER_OTLP_HEADERS shape the key accepts. */
    public const string OTLP_HEADERS = 'x-honeycomb-team=hcaik_SECRET,authorization=Bearer abc';

    public const string OTLP_ENDPOINT = 'https://api.honeycomb.io';

    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'firefly.management.endpoints.web.exposure.include' => 'health,info,env,prometheus,metrics,httpexchanges,process',
            'firefly.observability.tracing.enabled' => true,
            'firefly.observability.tracing.exporter' => 'none',
            'firefly.observability.tracing.otlp.endpoint' => self::OTLP_ENDPOINT,
            'firefly.observability.tracing.otlp.headers' => self::OTLP_HEADERS,
        ];
    }
}

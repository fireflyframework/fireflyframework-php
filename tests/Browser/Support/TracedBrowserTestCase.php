<?php

declare(strict_types=1);

namespace Firefly\Tests\Browser\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use RuntimeException;

/**
 * The skeleton with the observability wave switched on the way an operator would switch it on: tracing with
 * the in-process OpenTelemetry tracer and no exporter (spans are recorded for their ids and propagation and
 * exported nowhere — the trace id column and the log fields are what a browser can observe), histogram
 * buckets on every timer (so the HTTP server timer scrapes as a histogram), HTTP exchanges recorded, the two
 * management endpoints the scenarios read added to the exposure list, and structured logging in ECS. The
 * dashboard's own paths and the actuator's are excluded from the exchange ring, as the admin capstone does,
 * so the traffic page lists the visits and never the request that rendered it.
 *
 * THE LOG IS A FILE, AND IT IS THE DEFAULT CHANNEL. A JSON line is not visible in a browser and the wave
 * exposes no test-only sink, so the one honest observation point is the formatted line itself: Laravel's
 * `single` driver over a file under the compile temp dir, which StructuredLogging formats as ECS because it
 * is the `logging.default` channel (FireflyTestCase points the default at a filesystem-free `errorlog`
 * channel; this fixture points it here instead, and the deliberate 404s other suites provoke are not on this
 * fixture's visits). The fixture route GET /browser-fixture/log writes one line INSIDE its traced request,
 * so TraceContextLogProcessor stamps it with the SERVER span's ids, and the scenario reads that line back and
 * matches its trace id against the exchange row the same request left on /firefly/http and in
 * /actuator/httpexchanges.
 *
 * Every request the plugin serves runs inside a fiber; the tracer initialises the fiber's OTel context itself
 * (OpenTelemetryTracer::ensureFiberContext()), which is what makes tracing possible here at all.
 *
 * The helpers are PUBLIC: Pest 4 types a closure's $this as the TestCall.
 */
abstract class TracedBrowserTestCase extends BrowserTestCase
{
    public const string LOG_MESSAGE = 'browser fixture line';

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'firefly.observability.tracing.enabled' => true,
            'firefly.observability.tracing.exporter' => 'none',
            'firefly.observability.metrics.distribution.buckets' => [0.05, 0.5, 5],
            'firefly.observability.httpexchanges.enabled' => true,
            'firefly.observability.httpexchanges.exclude' => ['actuator', 'actuator/*', 'firefly', 'firefly/*'],
            'firefly.management.endpoints.web.exposure.include' => 'health,info,prometheus,metrics,httpexchanges',
            'firefly.logging.structured.format' => 'ecs',
            'logging.default' => 'browser',
            'logging.channels.browser' => ['driver' => 'single', 'path' => self::logFile(), 'level' => 'debug'],
        ];
    }

    /** The application log, under the compile temp dir SkeletonExampleTestCase owns; removed with it. */
    public static function logFile(): string
    {
        return (string) self::$cacheDir.'/browser.log';
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$cacheDir !== null && is_file(self::logFile())) {
            unlink(self::logFile());
        }

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/browser-fixture/log', static function (): string {
            Log::info(self::LOG_MESSAGE, ['fixture' => 'observability']);

            return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Log fixture</title></head><body>'
                .'<h1>Log fixture</h1><p id="logged">One line was written to the application log.</p>'
                .'</body></html>';
        });
    }

    /**
     * The last line the fixture route wrote, decoded — an ECS document when the format is on.
     *
     * @return array<string, mixed>
     */
    public function lastFixtureLogLine(): array
    {
        $lines = file(self::logFile(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach (array_reverse($lines) as $line) {
            if (str_contains($line, self::LOG_MESSAGE)) {
                /** @var array<string, mixed> $document */
                $document = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

                return $document;
            }
        }

        throw new RuntimeException('The fixture route never wrote its line to '.self::logFile());
    }

    /** The W3C trace id on that line — the SERVER span of the request that logged it. */
    public function fixtureTraceId(): string
    {
        $trace = $this->lastFixtureLogLine()['trace'] ?? null;
        $id = is_array($trace) ? ($trace['id'] ?? null) : null;
        if (! is_string($id) || preg_match('/^[0-9a-f]{32}$/', $id) !== 1) {
            throw new RuntimeException('The fixture line carries no W3C trace id.');
        }

        return $id;
    }
}

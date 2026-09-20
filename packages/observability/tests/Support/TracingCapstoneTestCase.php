<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Support;

use Firefly\Actuator\ActuatorServiceProvider;
use Firefly\Actuator\ActuatorWiringProvider;
use Firefly\Cqrs\Command\CommandBus;
use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Cqrs\Handler\HandlerRegistry;
use Firefly\Eda\EdaServiceProvider;
use Firefly\Eda\EdaWiringProvider;
use Firefly\Eda\Listener\EventListenerManifest;
use Firefly\Observability\ObservabilityServiceProvider;
use Firefly\Observability\ObservabilityWiringProvider;
use Firefly\Resilience\ResilienceServiceProvider;
use Firefly\Testing\Boot\FireflyBoot;
use Firefly\Testing\FireflyTestCase;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Web\WebServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use RuntimeException;
use Throwable;

/**
 * ObservabilityCapstoneTestCase's twin with tracing ON: the real Web layer, actuator, CQRS, EDA (in-memory) and
 * observability providers over Testbench, the OpenTelemetry SDK's InMemoryExporter bound as the
 * SpanExporterInterface bean (the seam OpenTelemetryAutoConfiguration::tracerProvider() honours), and a
 * handful of ordinary routes that exercise each instrumentation. Every span a request produces is read back
 * from the exporter, so what is asserted is the OTel span a backend would receive — kind, name, attributes,
 * parent — not a recording double's view of it.
 *
 * The EventListenerManifest is bound empty so the EDA wiring pass has nothing to subscribe; a test subscribes
 * its own closure on the EventPublisher bean instead. The CQRS handler manifest is likewise empty (no scanned
 * handlers in a test package), so setUp() registers the one CapstonePing handler on the HandlerRegistry bean by
 * hand — the same registry the real CommandBus resolves through, so /command exercises the traced bus.
 */
abstract class TracingCapstoneTestCase extends FireflyTestCase
{
    public InMemoryExporter $exporter;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var HandlerRegistry $handlers */
        $handlers = $this->app()->make(HandlerRegistry::class);
        $handlers->registerCommandHandler(CapstonePing::class, static fn (object $command): string => 'pinged');
    }

    protected function fireflyProviders(): array
    {
        return [
            ValidationServiceProvider::class,
            WebServiceProvider::class,
            ActuatorServiceProvider::class,
            ActuatorWiringProvider::class,
            CqrsServiceProvider::class,
            CqrsWiringProvider::class,
            EdaServiceProvider::class,
            EdaWiringProvider::class,
            ResilienceServiceProvider::class,
            ObservabilityServiceProvider::class,
            ObservabilityWiringProvider::class,
        ];
    }

    protected function configOverrides(): array
    {
        return [
            'app.name' => 'capstone-app',
            'app.env' => 'testing',
            'cache.default' => 'array',
            'firefly.management.enabled' => true,
            'firefly.management.endpoints.web.exposure.include' => 'health,info,prometheus,metrics,httpexchanges',
            'firefly.management.endpoint.health.db.enabled' => false,
            'firefly.observability.tracing.enabled' => true,
            'firefly.observability.tracing.service-name' => 'capstone',
            'firefly.eda.provider' => 'memory',
        ];
    }

    /**
     * The parameter stays untyped for the reason ObservabilityCapstoneTestCase::defineRoutes() gives: Testbench
     * declares it untyped, and narrowing a parameter in an override is an LSP violation PHP rejects.
     *
     * @param  Router  $router
     */
    protected function defineRoutes($router): void
    {
        $router->get('/demo/{id}', static fn (string $id): array => [
            'id' => $id,
            'traceId' => Context::get('firefly.trace_id'),
            'spanId' => Context::get('firefly.span_id'),
        ]);

        $router->get('/boom', static function (): never {
            throw new RuntimeException('boom');
        });

        // A command sent through the real CommandBus inside the request: the INTERNAL span nests under the SERVER span.
        $router->get('/command', static fn (CommandBus $bus): array => ['result' => $bus->send(new CapstonePing)]);

        // A 4xx the pipeline renders from a throwable: the NotFoundHttpException rides out on the response.
        $router->get('/missing', static function (): never {
            abort(404, 'no such thing');
        });

        // An outbound Http client call made inside the request: the CLIENT span nests under the SERVER span.
        $router->get('/outbound', static fn (): array => ['body' => Http::get('https://downstream.test/api/ping')->body()]);

        // An outbound call to a signed URL that fails to connect: the query must not reach the CLIENT span
        // through the exception's message, which Http::failedConnection() (like Guzzle 7) ends with the URI.
        $router->get('/outbound-signed', static function (): array {
            try {
                return ['body' => Http::get('https://downstream.test/api/object?X-Amz-Signature=secret')->body()];
            } catch (ConnectionException) {
                return ['error' => 'downstream unreachable'];
            }
        });

        // A fan-out through Http::pool(): every request's promise is built before any is awaited, and each
        // CLIENT span must still hang directly under the SERVER span — siblings, not a chain.
        $router->get('/fanout', static fn (): array => array_map(
            static fn (Response|Throwable $result): string => $result instanceof Response ? $result->body() : $result->getMessage(),
            Http::pool(static fn (Pool $pool): array => [
                $pool->as('a')->get('https://downstream.test/api/a'),
                $pool->as('b')->get('https://downstream.test/api/b'),
            ]),
        ));
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        FireflyBoot::stubScheduledManifest($app);

        $this->exporter = new InMemoryExporter;
        $app->instance(SpanExporterInterface::class, $this->exporter);
        $app->instance(EventListenerManifest::class, new EventListenerManifest([]));
    }

    /** @return list<ImmutableSpan> */
    public function spans(): array
    {
        /** @var list<ImmutableSpan> $spans */
        $spans = $this->exporter->getSpans();

        return $spans;
    }

    public function spanNamed(string $name): ?ImmutableSpan
    {
        foreach ($this->spans() as $span) {
            if ($span->getName() === $name) {
                return $span;
            }
        }

        return null;
    }
}

/** The command /command sends: a fixture with no handler class of its own, so a closure handler registered in setUp() serves it. */
final class CapstonePing {}

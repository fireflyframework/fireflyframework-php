<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Cqrs\Metrics\CqrsMetrics;
use Firefly\Cqrs\Metrics\NoOpCqrsMetrics;
use Firefly\Cqrs\Tracing\CqrsTracing;
use Firefly\Cqrs\Tracing\NoOpCqrsTracing;
use Firefly\Eda\EdaServiceProvider;
use Firefly\Eda\EdaWiringProvider;
use Firefly\Eda\Tracing\EdaTracing;
use Firefly\Eda\Tracing\NoOpEdaTracing;
use Firefly\Observability\Cqrs\MeterRegistryCqrsMetrics;
use Firefly\Observability\Cqrs\TracerCqrsTracing;
use Firefly\Observability\Eda\TracerEdaTracing;
use Firefly\Observability\Metrics\MeterRegistry;
use Firefly\Observability\ObservabilityServiceProvider;
use Firefly\Observability\ObservabilityWiringProvider;
use Illuminate\Foundation\Application;

/**
 * NOTE (brief-test fix): the brief's draft omitted a working `logging` config. Registering CqrsServiceProvider
 * brings in CqrsAutoConfiguration::domainEventBridge(), which resolves `Psr\Log\LoggerInterface` via
 * `$container->bound(...)` / `make(...)` — and a bare `Illuminate\Foundation\Application` already auto-registers
 * LogServiceProvider (registerBaseServiceProviders()) with `Psr\Log\LoggerInterface` aliased to `'log'`, so
 * `bound()` is unconditionally true. Resolving `'log'` for the first time fires
 * ObservabilityWiringProvider::register()'s `afterResolving('log', ...)`, which calls `$log->driver()` to attach
 * CorrelationIdLogProcessor. With NO `logging.default`/`logging.channels` config at all (or merely an empty
 * `channels` array — `firefly/actuator`'s own RealProviderBootTest gets away with that because it never resolves
 * `LoggerInterface`), `LogManager::get(null)` fails to resolve a default channel, and its internal catch(Throwable)
 * falls back to `createEmergencyLogger()` — which ITSELF immediately calls `->emergency(...)`, writing to
 * `storage_path('logs/laravel.log')`. That path doesn't exist in this bare app and the sandbox filesystem is
 * read-only outside the repo, so Monolog's `StreamHandler::createDir()` throws UNCAUGHT (the emergency-logger
 * write is outside LogManager's own try/catch). Fix: configure a real, filesystem-free default channel
 * (`errorlog`, a stock Monolog driver Laravel ships) so `driver()` resolves successfully and the emergency path is
 * never hit. This config is passed straight through to the harness's `fireflyApplication()` factory unchanged.
 *
 * @param  array<string, mixed>  $observability
 */
function bootObservability(array $observability): Application
{
    return fireflyApplication(
        config: [
            'firefly' => ['cqrs' => [], 'observability' => $observability],
            'logging' => ['default' => 'test', 'channels' => ['test' => ['driver' => 'errorlog']]],
        ],
        providers: [EdaServiceProvider::class, EdaWiringProvider::class, CqrsServiceProvider::class, CqrsWiringProvider::class, ObservabilityServiceProvider::class, ObservabilityWiringProvider::class],
    );
}

it('makes MeterRegistryCqrsMetrics win over the M10 NoOp when metrics are enabled', function () {
    /** @var ApplicationContext $context */
    $context = bootObservability(['metrics' => ['enabled' => true]])->make(ApplicationContext::class);

    expect($context->get(CqrsMetrics::class))->toBeInstanceOf(MeterRegistryCqrsMetrics::class)
        ->and($context->get(MeterRegistry::class))->toBeInstanceOf(MeterRegistry::class);
});

it('leaves the M10 NoOpCqrsMetrics and binds no MeterRegistry when metrics are disabled', function () {
    $app = bootObservability(['metrics' => ['enabled' => false]]);
    /** @var ApplicationContext $context */
    $context = $app->make(ApplicationContext::class);

    expect($context->get(CqrsMetrics::class))->toBeInstanceOf(NoOpCqrsMetrics::class)
        ->and($app->bound(MeterRegistry::class))->toBeFalse();
});

it('makes TracerCqrsTracing win over the cqrs NoOp when tracing is enabled, and leaves the NoOp otherwise', function () {
    /** @var ApplicationContext $traced */
    $traced = bootObservability(['tracing' => ['enabled' => true]])->make(ApplicationContext::class);
    /** @var ApplicationContext $plain */
    $plain = bootObservability([])->make(ApplicationContext::class);

    expect($traced->get(CqrsTracing::class))->toBeInstanceOf(TracerCqrsTracing::class)
        ->and($plain->get(CqrsTracing::class))->toBeInstanceOf(NoOpCqrsTracing::class);
});

it('leaves the cqrs NoOp when the cqrs instrumentation switch is off under an enabled tracing master gate', function () {
    /** @var ApplicationContext $context */
    $context = bootObservability(['tracing' => ['enabled' => true, 'cqrs' => ['enabled' => false]]])->make(ApplicationContext::class);

    expect($context->get(CqrsTracing::class))->toBeInstanceOf(NoOpCqrsTracing::class);
});

it('makes TracerEdaTracing win over the eda NoOp when tracing is enabled, and leaves the NoOp otherwise', function () {
    /** @var ApplicationContext $traced */
    $traced = bootObservability(['tracing' => ['enabled' => true]])->make(ApplicationContext::class);
    /** @var ApplicationContext $plain */
    $plain = bootObservability([])->make(ApplicationContext::class);

    expect($traced->get(EdaTracing::class))->toBeInstanceOf(TracerEdaTracing::class)
        ->and($plain->get(EdaTracing::class))->toBeInstanceOf(NoOpEdaTracing::class);
});

<?php

declare(strict_types=1);

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Cqrs\Metrics\CqrsMetrics;
use Firefly\Cqrs\Metrics\NoOpCqrsMetrics;
use Firefly\Observability\Cqrs\MeterRegistryCqrsMetrics;
use Firefly\Observability\Metrics\MeterRegistry;
use Firefly\Observability\ObservabilityServiceProvider;
use Firefly\Observability\ObservabilityWiringProvider;
use Illuminate\Config\Repository;
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
 * never hit.
 *
 * @param  array<string, mixed>  $observability
 */
function bootObservability(array $observability): Application
{
    $app = new Application;
    $app->instance('config', new Repository([
        'firefly' => ['cqrs' => [], 'observability' => $observability],
        'logging' => ['default' => 'test', 'channels' => ['test' => ['driver' => 'errorlog']]],
    ]));
    $app->register(new FireflyAutoConfigureServiceProvider($app));
    $app->register(new CqrsServiceProvider($app));
    $app->register(new CqrsWiringProvider($app));
    $app->register(new ObservabilityServiceProvider($app));
    $app->register(new ObservabilityWiringProvider($app));
    $app->boot();

    return $app;
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

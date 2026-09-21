<?php

declare(strict_types=1);

namespace Firefly\Observability\Boot;

use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Observability\Tracing\Tracer;
use Firefly\Observability\Tracing\W3CTraceContextPropagator;
use Firefly\Observability\Web\HttpClientTracingMiddleware;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Installs HttpClientTracingMiddleware on the Laravel Http client factory once at boot — the one place a
 * process-wide Guzzle middleware can be registered (Illuminate\Http\Client\Factory::globalMiddleware()). A
 * BootPass rather than a #[Component], because there is no bean to declare: the middleware is a callable the
 * factory holds, and the factory is Laravel's singleton, not ours.
 *
 * Runs at WiringPasses like MeterBindingsPass, strictly after EagerSingletons has bound the Tracer bean.
 * Reads the two gates itself (the master gate and `tracing.http-client.enabled`) — a pass has no
 * #[ConditionalOnProperty] — so a disabled switch costs one config read. Laravel's Http::fake() keeps the same
 * factory instance and only adds stubs, so a middleware installed here is exercised by faked requests too.
 */
final class HttpClientTracingPass implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::WiringPasses;
    }

    public function order(): int
    {
        return 0;
    }

    public function run(BootContext $context): void
    {
        $config = $context->config;
        if (! $config->bool('firefly.observability.tracing.enabled', false)
            || ! $config->bool('firefly.observability.tracing.http-client.enabled', true)) {
            return;
        }

        $container = $context->container;
        if (! class_exists(HttpFactory::class) || ! $container->bound(Tracer::class)) {
            return;
        }

        /** @var Tracer $tracer */
        $tracer = $container->make(Tracer::class);
        /** @var HttpFactory $factory */
        $factory = $container->make(HttpFactory::class);

        $factory->globalMiddleware(new HttpClientTracingMiddleware($tracer, new W3CTraceContextPropagator));
    }
}

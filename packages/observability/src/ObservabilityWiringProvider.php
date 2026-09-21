<?php

declare(strict_types=1);

namespace Firefly\Observability;

use Firefly\Config\Config;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\Observability\Boot\HttpClientTracingPass;
use Firefly\Observability\Boot\LogChannelWiringPass;
use Firefly\Observability\Boot\MeterBindingsPass;
use Firefly\Observability\Logging\LogChannelWiring;
use Firefly\Observability\Logging\StructuredLogging;
use Illuminate\Log\LogManager;

/**
 * The boot-pass + default-binding half of firefly/observability (cannot ride on ObservabilityServiceProvider —
 * AutoConfiguration's final register() records candidacy only). ObservabilityAutoConfiguration (the #[Configuration]
 * bean source) binds the framework infrastructure collectors (MeterRegistry, MetricsRecorder, PrometheusTextFormat,
 * Tracer, the real CqrsMetrics) as #[Bean]s; this class contributes MeterBindingsPass — which registers the
 * process/circuit-breaker gauges into the MeterRegistry once it is bound — HttpClientTracingPass, which installs
 * the outbound-HTTP tracing middleware on the Http client factory when tracing is on, and LogChannelWiringPass,
 * which puts the correlation-id and trace-context processors on every configured log channel, and the
 * structured formatter when `firefly.logging.structured.format` names one. Both this and
 * ObservabilityServiceProvider are in extra.laravel.providers.
 *
 * THE LOG WIRING RUNS FROM TWO PLACES, ON PURPOSE. LogChannelWiringPass is the guarantee: it validates the two
 * `firefly.logging.structured.*` keys from Application::boot() — an unknown format or channel refuses the boot,
 * loudly, whoever resolved `log` first — and wires whatever LogManager the container holds by then. The
 * `afterResolving('log', ...)` hook installed in register() is the early path: a line written between this
 * register() and the wiring passes (a provider's boot(), a #[PostConstruct] on an eager singleton) already
 * carries the ids and the format instead of landing in an aggregator as one plain-text line among the JSON.
 * The hook is NOT what the refusal rests on — Container::resolve() caches the singleton before it fires
 * resolving callbacks, and Laravel's exception handler swallows anything the first resolution of `log` throws
 * (see the pass's docblock) — and it does not fire at all for a `log` resolved before this register() ran.
 * Both go through LogChannelWiring::attach(), which is idempotent per processor (each pushed only where its
 * class is not on the logger yet) and re-sets the formatter freely, so the ordinary boot — the pass's own
 * make('log') fires the hook, then the pass goes over the same channels — stacks nothing twice.
 */
final class ObservabilityWiringProvider extends FireflyServiceProvider
{
    /**
     * @return list<BootPass>
     */
    public function passes(): array
    {
        return [new MeterBindingsPass, new HttpClientTracingPass, new LogChannelWiringPass];
    }

    public function register(): void
    {
        $this->app->afterResolving('log', function (object $log): void {
            if (! $log instanceof LogManager) {
                return;
            }

            /** @var Config $config */
            $config = $this->app->make(Config::class);

            (new LogChannelWiring($this->app, new StructuredLogging($config)))->attach($log);
        });

        parent::register();
    }
}

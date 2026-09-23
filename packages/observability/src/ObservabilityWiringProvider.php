<?php

declare(strict_types=1);

namespace Firefly\Observability;

use Firefly\Config\Config;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\Observability\Boot\HttpClientTracingPass;
use Firefly\Observability\Boot\LogChannelWiringPass;
use Firefly\Observability\Boot\MeterBindingsPass;
use Firefly\Observability\Logging\FireflyContextLogProcessor;
use Firefly\Observability\Logging\LogChannelWiring;
use Firefly\Observability\Logging\StructuredLogging;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Log\ContextLogProcessor as ContextLogProcessorContract;
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
 *
 * AND FROM A THIRD, WHICH IS THE ONLY ONE THAT REACHES A CHANNEL NOBODY CONFIGURED. Both places above walk
 * the channels `logging.channels` names, which is every channel an application CONFIGURES and none of the
 * ones it BUILDS: `Log::build([...])` makes an `ondemand` channel out of an array neither of them ever sees.
 * register() therefore also rebinds Laravel's own `ContextLogProcessor` contract — the processor
 * LogManager::get() hands to EVERY channel it builds — to FireflyContextLogProcessor, which wraps the
 * binding it replaced. See rebindContextLogProcessor() for why that read happens at register() time and how
 * the gate `firefly.logging.structured.all-channels` turns it off.
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

        $this->rebindContextLogProcessor();

        parent::register();
    }

    /**
     * THE ONE SEAM THAT REACHES A CHANNEL BUILT AFTER BOOT. LogChannelWiring can only dress the channels
     * `logging.channels` names; `Log::build(['driver' => 'single', 'path' => …])` makes an `ondemand` channel
     * out of an array neither the boot pass nor the afterResolving('log') hook above ever sees. Every channel
     * LogManager builds — configured, stacked, or made on demand — is handed
     * `$this->app->make(ContextLogProcessor::class)` (LogManager::get()), so rebinding THAT contract to the
     * decorator is what reaches all of them. Laravel's own binding is captured here and preserved inside the
     * decorator, so `Context::add()` keeps working exactly as it did.
     *
     * Read at register() time, from the `config` repository rather than the Config binding, for the reason
     * SecurityWiringProvider::methodManifest() reads its own key that way: `config` is bound by the
     * LoadConfiguration bootstrapper before any provider registers, and this decision has to be made before
     * anything resolves `log` — a channel already built cannot be re-dressed by a later binding.
     *
     * Guarded against double wrapping: a second register() on the same container (a re-registered provider,
     * an Octane worker rebuilding its container in place) would otherwise capture the decorator as the inner
     * processor and stack a second identical pass over every record.
     */
    private function rebindContextLogProcessor(): void
    {
        /** @var Repository $repository */
        $repository = $this->app->make('config');

        if (! (new Config($repository))->bool('firefly.logging.structured.all-channels', true)) {
            return;
        }

        $existing = $this->app->bound(ContextLogProcessorContract::class)
            ? $this->app->make(ContextLogProcessorContract::class)
            : null;

        if ($existing instanceof FireflyContextLogProcessor) {
            return;
        }

        $this->app->bind(
            ContextLogProcessorContract::class,
            static fn (Container $app): ContextLogProcessorContract => new FireflyContextLogProcessor($app, $existing),
        );
    }
}

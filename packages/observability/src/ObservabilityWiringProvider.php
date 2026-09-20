<?php

declare(strict_types=1);

namespace Firefly\Observability;

use Firefly\Config\Config;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\Observability\Boot\HttpClientTracingPass;
use Firefly\Observability\Boot\MeterBindingsPass;
use Firefly\Observability\Logging\CorrelationIdLogProcessor;
use Firefly\Observability\Logging\StructuredLogging;
use Firefly\Observability\Logging\TraceContextLogProcessor;
use Firefly\Observability\Tracing\Tracer;
use Illuminate\Log\Logger;
use Illuminate\Log\LogManager;
use Monolog\Logger as MonologLogger;

/**
 * The boot-pass + default-binding half of firefly/observability (cannot ride on ObservabilityServiceProvider —
 * AutoConfiguration's final register() records candidacy only). ObservabilityAutoConfiguration (the #[Configuration]
 * bean source) binds the framework infrastructure collectors (MeterRegistry, MetricsRecorder, PrometheusTextFormat,
 * Tracer, the real CqrsMetrics) as #[Bean]s; this class contributes MeterBindingsPass — which registers the
 * process/circuit-breaker gauges into the MeterRegistry once it is bound — and HttpClientTracingPass, which
 * installs the outbound-HTTP tracing middleware on the Http client factory when tracing is on, plus the log
 * wiring: the correlation-id and trace-context processors on every configured channel, and the structured
 * formatter when `firefly.logging.structured.format` names one. Both this and ObservabilityServiceProvider are in
 * extra.laravel.providers.
 */
final class ObservabilityWiringProvider extends FireflyServiceProvider
{
    /**
     * @return list<BootPass>
     */
    public function passes(): array
    {
        return [new MeterBindingsPass, new HttpClientTracingPass];
    }

    public function register(): void
    {
        $this->app->afterResolving('log', function (object $log): void {
            $this->attachLogProcessors($log);
        });

        parent::register();
    }

    /**
     * Attaches the framework's log processors — and, when `firefly.logging.structured.format` names one, the
     * structured formatter — to each configured channel's real Monolog logger.
     *
     * Deliberately NOT the naive `method_exists($log, 'pushProcessor') && $log->pushProcessor(...)`:
     * `afterResolving('log', ...)` hands back the raw `Illuminate\Log\LogManager`, which does NOT literally
     * declare `pushProcessor` — it only forwards unknown calls to `$this->driver()` via `__call()`, and
     * `method_exists()` is blind to `__call`, so that guard is ALWAYS false and the processor would silently
     * never attach (confirmed empirically). The fix mirrors Laravel's OWN idiom for attaching its built-in
     * ContextLogProcessor (LogManager::get()): resolve the concrete per-channel Illuminate\Log\Logger via
     * channel(), unwrap its real Monolog\Logger via getLogger() (a literal method on Illuminate\Log\Logger),
     * and push there — every check below is a real, statically-checkable instanceof narrowing.
     *
     * Resolving a channel here forces its lazy creation the moment 'log' is first resolved rather than at the
     * first Log:: write — functionally equivalent (LogManager caches channels by name). The Tracer is
     * resolved lazily inside TraceContextLogProcessor on every record, because at this point in a boot
     * nothing may be bound yet and the log service's first resolution must never depend on the tracing
     * auto-configuration having run. There is deliberately no try/catch around channel(): LogManager::get()
     * catches every Throwable itself and returns a fresh, uncached emergency logger, so channel() never
     * throws — a catch here would be dead code — and a name that does not exist under logging.channels is
     * instead refused by StructuredLogging::channels() with a ConfigurationException before anything is
     * resolved, because the alternative is the processors and the formatter landing on that throw-away
     * emergency logger while the channel the application actually writes to silently keeps plain text with
     * no ids at all. A channel that IS defined but whose driver fails to build still gets Laravel's
     * emergency logger, exactly as it would without this provider.
     */
    private function attachLogProcessors(object $log): void
    {
        if (! $log instanceof LogManager) {
            return;
        }

        /** @var Config $config */
        $config = $this->app->make(Config::class);
        $structured = new StructuredLogging($config);

        foreach ($structured->channels() as $name) {
            $channel = $log->channel($name);
            if (! $channel instanceof Logger) {
                continue;
            }

            $monolog = $channel->getLogger();
            if (! $monolog instanceof MonologLogger) {
                continue;
            }

            $monolog->pushProcessor(new CorrelationIdLogProcessor);
            $monolog->pushProcessor(new TraceContextLogProcessor(function (): ?Tracer {
                if (! $this->app->bound(Tracer::class)) {
                    return null;
                }

                /** @var Tracer $tracer */
                $tracer = $this->app->make(Tracer::class);

                return $tracer;
            }));

            $structured->apply($monolog);
        }
    }
}

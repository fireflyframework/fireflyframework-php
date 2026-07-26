<?php

declare(strict_types=1);

namespace Firefly\Observability;

use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\Observability\Logging\CorrelationIdLogProcessor;
use Illuminate\Log\Logger;
use Illuminate\Log\LogManager;
use Monolog\Logger as MonologLogger;

/**
 * The boot-pass + default-binding half of firefly/observability (cannot ride on ObservabilityServiceProvider —
 * AutoConfiguration's final register() records candidacy only). Later tasks bind the framework infrastructure
 * collectors (MeterRegistry, the Prometheus/Micrometer-JSON exposition endpoints, the HTTP instrumentation filter)
 * behind bound() guards here — the exact WebServiceProvider idiom — and contribute the bean-scan/route BootPasses
 * via passes(). Both this and ObservabilityServiceProvider are in extra.laravel.providers.
 */
final class ObservabilityWiringProvider extends FireflyServiceProvider
{
    /**
     * @return list<BootPass>
     */
    public function passes(): array
    {
        return [];
    }

    public function register(): void
    {
        $this->app->afterResolving('log', function (object $log): void {
            $this->attachCorrelationIdProcessor($log);
        });

        parent::register();
    }

    /**
     * Attaches CorrelationIdLogProcessor to the DEFAULT channel's real Monolog logger — deliberately NOT the
     * naive `method_exists($log, 'pushProcessor') && $log->pushProcessor(...)` a first read of the task brief
     * suggests: `afterResolving('log', ...)` hands back the raw `Illuminate\Log\LogManager` returned by
     * LogServiceProvider's `singleton('log', fn ($app) => new LogManager($app))`, and LogManager does NOT
     * literally declare a `pushProcessor` method — it only forwards unknown calls to `$this->driver()` via
     * `__call()`. `method_exists()` is blind to `__call` magic, so that guard is ALWAYS false for a bare
     * LogManager and the processor would silently never attach in any real application (confirmed empirically
     * against the installed illuminate/log version — see the task report). The fix mirrors Laravel's OWN
     * internal idiom for attaching its built-in Illuminate\Log\Context\ContextLogProcessor
     * (Illuminate\Log\LogManager::get()): resolve the concrete per-channel Illuminate\Log\Logger via driver(),
     * unwrap its real Monolog\Logger via getLogger() (a literal method on Illuminate\Log\Logger, unlike
     * pushProcessor), and push the processor there directly — every check below is a real, statically-checkable
     * instanceof narrowing, not a magic-method-blind method_exists() guess.
     *
     * Resolving the default channel here (via driver()) forces its lazy creation at the moment 'log' is first
     * resolved rather than at first Log:: write — functionally equivalent (LogManager caches channels by name
     * regardless of when they're first built) and the same "first use" moment the naive guard was already
     * trying to hook.
     */
    private function attachCorrelationIdProcessor(object $log): void
    {
        if (! $log instanceof LogManager) {
            return;
        }

        $channel = $log->driver();
        if (! $channel instanceof Logger) {
            return;
        }

        $monolog = $channel->getLogger();
        if ($monolog instanceof MonologLogger) {
            $monolog->pushProcessor(new CorrelationIdLogProcessor);
        }
    }
}

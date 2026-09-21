<?php

declare(strict_types=1);

namespace Firefly\Observability\Logging;

use Firefly\Observability\Tracing\Tracer;
use Illuminate\Contracts\Container\Container;
use Illuminate\Log\Logger;
use Illuminate\Log\LogManager;
use Monolog\Logger as MonologLogger;

/**
 * Puts the framework on a LogManager's configured channels: the correlation-id and trace-context processors on
 * each channel StructuredLogging::channels() names and, when `firefly.logging.structured.format` names one, the
 * structured formatter (StructuredLogging::apply()) on that channel's handlers. The ONE routine behind both of
 * the places that reach a LogManager — LogChannelWiringPass at boot, which is the guarantee, and
 * ObservabilityWiringProvider's afterResolving('log') hook, which is the early path for a line written before
 * the wiring passes run — so the two can never drift, and idempotent per channel (see alreadyWired()) so their
 * meeting on the same LogManager, which is the ordinary boot, stacks nothing twice.
 *
 * Deliberately NOT the naive `method_exists($log, 'pushProcessor') && $log->pushProcessor(...)`: a LogManager
 * does NOT literally declare `pushProcessor` — it only forwards unknown calls to `$this->driver()` via
 * `__call()`, and `method_exists()` is blind to `__call`, so that guard is ALWAYS false and the processor
 * would silently never attach (confirmed empirically). This mirrors Laravel's OWN idiom for attaching its
 * built-in ContextLogProcessor (LogManager::get()): resolve the concrete per-channel Illuminate\Log\Logger via
 * channel(), unwrap its real Monolog\Logger via getLogger() (a literal method on Illuminate\Log\Logger), and
 * push there — every check below is a real, statically-checkable instanceof narrowing.
 *
 * Resolving a channel here forces its lazy creation — at boot, or the moment 'log' is first resolved — rather
 * than at the first Log:: write; functionally equivalent, LogManager caches channels by name. The Tracer is
 * resolved lazily inside TraceContextLogProcessor on every record, because nothing may be bound yet when the
 * early path runs and the log service's first resolution must never depend on the tracing auto-configuration
 * having run. There is deliberately no try/catch around channel(): LogManager::get() catches every Throwable
 * itself and returns a fresh, uncached emergency logger, so channel() never throws — a catch here would be dead
 * code. A name that does not exist under logging.channels is refused by StructuredLogging::channels() with a
 * ConfigurationException before a single channel is built — the alternative is the processors and the formatter
 * landing on that throw-away emergency logger while the channel the application actually writes to silently
 * keeps plain text with no ids at all. The one name channels() does NOT vouch for is the `logging.default`
 * fallback, and an undefined one is skipped here rather than built: there is no real logger to put anything on,
 * and building it means LogManager writing its "Unable to create configured logger" emergency line to
 * storage/logs on every boot — a `logging.default` that names nothing is Laravel's own misconfiguration, and its
 * emergency logger is loud about it on the first write exactly as it would be without this package. A channel
 * that IS defined but whose driver fails to build still gets Laravel's emergency logger, as it would anyway.
 */
final class LogChannelWiring
{
    public function __construct(
        private readonly Container $container,
        private readonly StructuredLogging $structured,
    ) {}

    /**
     * Validates the two keys first (an unknown format or channel throws a ConfigurationException before anything
     * is built), then wires each configured channel of $log that is not wired yet.
     */
    public function attach(LogManager $log): void
    {
        $this->structured->format();

        foreach ($this->structured->channels() as $name) {
            if (! $this->structured->defines($name)) {
                continue;
            }

            $channel = $log->channel($name);
            if (! $channel instanceof Logger) {
                continue;
            }

            $monolog = $channel->getLogger();
            if (! $monolog instanceof MonologLogger || self::alreadyWired($monolog)) {
                continue;
            }

            $monolog->pushProcessor(new CorrelationIdLogProcessor);
            $monolog->pushProcessor(new TraceContextLogProcessor(function (): ?Tracer {
                if (! $this->container->bound(Tracer::class)) {
                    return null;
                }

                /** @var Tracer $tracer */
                $tracer = $this->container->make(Tracer::class);

                return $tracer;
            }));

            $this->structured->apply($monolog);
        }
    }

    /**
     * Whether attach() has been here: the CorrelationIdLogProcessor is the first thing it pushes and the framework
     * pushes one nowhere else, so its presence on a channel's Monolog logger is the mark. State on the logger
     * itself rather than a flag on this object, because the early hook and the boot pass hold different
     * instances of this class and the mark has to be visible to both.
     */
    private static function alreadyWired(MonologLogger $monolog): bool
    {
        foreach ($monolog->getProcessors() as $processor) {
            if ($processor instanceof CorrelationIdLogProcessor) {
                return true;
            }
        }

        return false;
    }
}

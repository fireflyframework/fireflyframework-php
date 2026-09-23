<?php

declare(strict_types=1);

namespace Firefly\Observability\Logging;

use Closure;
use Firefly\Observability\Tracing\Tracer;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Log\ContextLogProcessor as ContextLogProcessorContract;
use Illuminate\Log\Context\ContextLogProcessor as LaravelContextLogProcessor;
use Monolog\LogRecord;

/**
 * THE CHANNEL BUILT AFTER BOOT GETS THE IDS TOO.
 *
 * LogChannelWiring dresses every channel named in `logging.channels` — at boot, and again through the
 * `afterResolving('log')` hook — which covers every channel an application CONFIGURES. It does not cover a
 * channel an application BUILDS: `Log::build(['driver' => 'single', 'path' => …])` creates an `ondemand`
 * channel out of an array LogChannelWiring never sees, so a line written to it carried neither the
 * correlation id nor the trace ids. That was the documented limitation, and the shape of code that hits it
 * — a job writing to a per-tenant file, an ad-hoc audit channel — is exactly the code whose lines most need
 * to be correlatable.
 *
 * Laravel already has the hook, and it is not a hook we had to ask for: `LogManager::get()` pushes
 * `$this->app->make(ContextLogProcessor::class)` onto EVERY channel it builds, `build()` included, and the
 * contract is a container binding `Illuminate\Log\Context\ContextServiceProvider` fills with Laravel's own
 * implementation. Rebinding it to this decorator means every channel — configured, stacked, on-demand, or
 * created by a package five layers away — carries the ids, resolved per record so the Tracer does not have
 * to be bound when the binding is made.
 *
 * THE INNER PROCESSOR IS PRESERVED, NOT REPLACED. Laravel's own processor is what puts the application's
 * `Context::add()` values on a record; swallowing it to add three ids would trade one feature for another.
 * It is captured at construction (the existing binding, resolved once) and applied FIRST, so the framework's
 * ids are written over a user key of the same name rather than under it — the same precedence
 * ErrorResponse::toArray() gives its standard members over extensions, and for the same reason: a value the
 * framework guarantees must not be forgeable by an application's own context key.
 *
 * THE TWO PROCESSORS ARE THE SAME TWO LogChannelWiring PUSHES, constructed the same way, so a channel dressed
 * here is indistinguishable from a configured one: CorrelationIdLogProcessor's normalised `correlation_id`
 * field, and TraceContextLogProcessor's trace/span/correlation/request ids with the Tracer resolved through a
 * closure on every record rather than captured — this object is built the first time `log` is resolved, which
 * can be long before the tracing auto-configuration has bound a Tracer at all. A configured channel therefore
 * carries both this decorator and the two processors LogChannelWiring already pushed; both write the same
 * fields from the same Context, so the second pass over a record changes nothing.
 *
 * WHAT THIS DOES NOT FIX, and the module doc says so: the structured FORMATTER. A channel's formatter is set
 * on its HANDLERS, which an on-demand channel builds from a config array this package never sees, and there
 * is no container seam equivalent to this one for them. An on-demand channel therefore carries the ids and
 * Monolog's LineFormatter — which is strictly better than before and still not the whole promise.
 */
final class FireflyContextLogProcessor implements ContextLogProcessorContract
{
    private readonly ContextLogProcessorContract $inner;

    public function __construct(
        private readonly Container $container,
        ?ContextLogProcessorContract $inner = null,
    ) {
        $this->inner = $inner ?? new LaravelContextLogProcessor;
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $record = ($this->inner)($record);

        foreach ([new CorrelationIdLogProcessor, new TraceContextLogProcessor($this->tracer())] as $processor) {
            $record = $processor($record);
        }

        return $record;
    }

    /**
     * The same lazy Tracer lookup LogChannelWiring hands TraceContextLogProcessor: resolved on every record,
     * null while nothing is bound, so a line written before (or entirely without) the tracing
     * auto-configuration carries the Context-published ids instead of failing to resolve a port.
     *
     * @return Closure(): ?Tracer
     */
    private function tracer(): Closure
    {
        return function (): ?Tracer {
            if (! $this->container->bound(Tracer::class)) {
                return null;
            }

            /** @var Tracer $tracer */
            $tracer = $this->container->make(Tracer::class);

            return $tracer;
        };
    }
}

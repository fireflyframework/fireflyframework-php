<?php

declare(strict_types=1);

namespace Firefly\Observability\Boot;

use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Observability\Logging\LogChannelWiring;
use Firefly\Observability\Logging\StructuredLogging;
use Illuminate\Log\LogManager;

/**
 * The boot-time half of `firefly.logging.structured.*` — the one that makes the two promises the keys carry
 * actually hold: an unknown format or channel refuses the boot, and every configured channel carries the id
 * processors and the formatter for the whole life of the process.
 *
 * WHY A PASS AND NOT THE HOOK ALONE. ObservabilityWiringProvider::register() also attaches through
 * `afterResolving('log', ...)`, and that hook is the early path for a line written before this phase — but it is
 * not a guarantee of anything, for two reasons verified against the installed laravel/framework. First,
 * Container::resolve() stores a singleton in $instances BEFORE it fires the resolving callbacks, and
 * Illuminate\Foundation\Exceptions\Handler::report() resolves its logger inside
 * `try { $this->newLogger(); } catch (Exception) { throw $e; }` — so whenever the FIRST resolution of `log`
 * in a process is an exception report (any request whose first log use is an uncaught domain exception), the
 * ConfigurationException the hook raises is swallowed, the original exception is rethrown unlogged, and the
 * cached LogManager keeps neither the processors nor the formatter for the rest of the process: under Octane
 * forever, under FPM on every such request, with no line anywhere saying why. Second, a `log` that was already
 * resolved when the provider registered — Laravel registers package providers alphabetically, and an earlier one
 * logging from register() is all it takes — never fires the hook at all. A pass has neither problem: it runs
 * from Application::boot() regardless of who resolved `log` first (a boot-time throw is reliably loud — the HTTP
 * and console kernels catch it around bootstrap, report it, and when that report is what first resolves `log`
 * the swallow rethrows the ConfigurationException itself, uncaught; either way nothing is served), and it
 * wires whatever LogManager instance the container holds, cached or not.
 *
 * Validates FIRST and unconditionally — even in a container with no `log` binding — because the refusal is the
 * contract, and it must not depend on how, or whether, the log service resolves. Runs at WiringPasses like
 * MeterBindingsPass and HttpClientTracingPass; it needs no bean (the Tracer is resolved per record, lazily),
 * only Config and the `log` singleton LogServiceProvider bound at register time. Under the FPM baseline that is
 * one LogManager and its configured channels built per request, the same lazy Monolog handlers LogManager
 * would build at the first write; under Octane, once per worker. Idempotent through LogChannelWiring: in the
 * ordinary boot its own make('log') fires the hook first, and the pass then finds every processor already on
 * each channel and has only the (idempotent) formatter to set again.
 */
final class LogChannelWiringPass implements BootPass
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
        $structured = new StructuredLogging($context->config);
        $structured->format();
        $structured->channels();

        $container = $context->container;
        $log = $container->bound('log') ? $container->make('log') : null;
        if (! $log instanceof LogManager) {
            return;
        }

        (new LogChannelWiring($container, $structured))->attach($log);
    }
}

<?php

declare(strict_types=1);

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Observability\Logging\CorrelationIdLogProcessor;
use Firefly\Observability\ObservabilityServiceProvider;
use Firefly\Observability\ObservabilityWiringProvider;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Orchestra\Testbench\TestCase;

// Deviation from the brief's literal test (documented in the task report): the brief's bare-PHP version of this
// test calls the Context facade with no booted Illuminate application, which throws
// `RuntimeException: A facade root has not been set.` — reproduced and confirmed against this exact installed
// Illuminate version before converting. Orchestra\Testbench\TestCase boots a real (minimal) Application and wires
// Facade::setFacadeApplication(...) during setUp(), which is exactly what the brief's own implementer note
// anticipated ("if the bare test can't resolve it, convert to a testbench test").
uses(TestCase::class);

it('adds the correlation id from Context to the record extra', function () {
    Context::add('firefly.correlation_id', 'corr-123');

    $record = new LogRecord(new DateTimeImmutable, 'test', Level::Info, 'hello', [], []);
    $out = (new CorrelationIdLogProcessor)($record);

    expect($out->extra['correlation_id'])->toBe('corr-123');
});

it('leaves the record extra untouched when Context carries no correlation id', function () {
    $record = new LogRecord(new DateTimeImmutable, 'test', Level::Info, 'hello', [], ['foo' => 'bar']);
    $out = (new CorrelationIdLogProcessor)($record);

    expect($out->extra)->toBe(['foo' => 'bar']);
});

/**
 * Proves the OTHER half of this task — ObservabilityWiringProvider actually WIRES the processor onto a real
 * Log:: write, not just that the processor class is correct in isolation. This closes a real gap the brief's
 * own Step 4 code left open: `$this->app->afterResolving('log', fn (object $log) => ...)` hands back the
 * `Illuminate\Log\LogManager` itself, and `LogManager` does NOT literally declare `pushProcessor` (it only
 * forwards unknown calls to `$this->driver()` via `__call`) — so `method_exists($log, 'pushProcessor')`, as
 * the brief spells it, is ALWAYS false for that object and the processor would silently never be attached in
 * any real application. Confirmed empirically (see the task report) before fixing the production code to drill
 * into the resolved channel's underlying Monolog logger, exactly mirroring Laravel's own internal idiom for
 * attaching Illuminate\Log\Context\ContextLogProcessor (LogManager::get()).
 */
it('wires CorrelationIdLogProcessor onto the resolved log channel so a real Log:: write carries the correlation id', function () {
    // A plain `bind()` closure, NOT `instance()`: Illuminate\Log\LogManager::createMonologDriver() resolves the
    // handler via `$this->app->make($config['handler'], $with)` where `$with` always carries a non-empty
    // `['level' => ...]` array — Container::resolve() treats ANY non-empty $parameters as `$needsContextualBuild`
    // and SKIPS the `instances[]` singleton short-circuit even when an `instance()` binding exists, silently
    // building a brand-new (different) TestHandler instead of the one under test. A `bind()` closure sidesteps
    // this entirely: Container::build() on a Closure concrete just invokes it (ignoring $parameters), so closing
    // over $handler here guarantees the SAME object is what LogManager wires into the real Monolog logger.
    // Verified empirically against this installed illuminate/container version before writing this comment.
    $handler = new TestHandler;
    app()->bind(TestHandler::class, fn () => $handler);

    config()->set('logging.channels.observability_test', ['driver' => 'monolog', 'handler' => TestHandler::class]);
    config()->set('logging.default', 'observability_test');

    app()->register(FireflyAutoConfigureServiceProvider::class);
    app()->register(ObservabilityServiceProvider::class);
    app()->register(ObservabilityWiringProvider::class);

    Context::add('firefly.correlation_id', 'corr-xyz');

    Log::info('hello from the capstone');

    // Filtered (not indexed) deliberately: PHPStan can't narrow `getRecords()`'s key type through Pest's
    // `->not->toBeEmpty()`, so an array-offset lookup would be a possibly-invalid-offset false positive.
    // array_filter() needs no such narrowing and mirrors the exact idiom MetricsFilterTest already uses.
    $matching = array_filter(
        $records = $handler->getRecords(),
        static fn (LogRecord $record): bool => ($record->extra['correlation_id'] ?? null) === 'corr-xyz',
    );

    expect($records)->not->toBeEmpty()
        ->and($matching)->not->toBeEmpty();
});

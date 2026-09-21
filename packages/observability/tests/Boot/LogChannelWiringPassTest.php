<?php

declare(strict_types=1);

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Context\Boot\BootPhase;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Observability\Boot\LogChannelWiringPass;
use Firefly\Observability\Logging\CorrelationIdLogProcessor;
use Firefly\Observability\Logging\ServiceContextLogProcessor;
use Firefly\Observability\Logging\TraceContextLogProcessor;
use Firefly\Observability\ObservabilityServiceProvider;
use Firefly\Observability\ObservabilityWiringProvider;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Log\Logger;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Facade;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;

/**
 * The boot-time guarantee behind `firefly.logging.structured.*`, exercised through the REAL provider stack and a
 * real Application::boot() (the shape of fireflyApplication(), unrolled so a test can act BETWEEN register() and
 * boot()): the pass is what makes "refuses to boot" and "every configured channel carries the ids" true no matter
 * who resolves `log` first — StructuredLoggingWiringTest covers the afterResolving('log') early path over a
 * Testbench Log:: write, and this file covers the two cases that path cannot: a refusal Laravel swallowed, and a
 * LogManager that already existed when the provider registered.
 *
 * `Illuminate\Foundation\Exceptions\Handler` is bound the way bootstrap/app.php binds it, because the case that
 * matters is its report(): `try { $this->newLogger(); } catch (Exception) { throw $e; }` (verified against the
 * installed laravel/framework) swallows anything the first resolution of `log` throws and rethrows the exception
 * being reported instead, while Container::resolve() has ALREADY cached the singleton — so, for the rest of the
 * process, nothing would ever refuse and nothing would ever attach.
 *
 * @param  array<string, mixed>  $structured  the `firefly.logging.structured` block
 */
function bareLogApplication(TestHandler $handler, array $structured = ['format' => 'json']): Application
{
    $app = new Application;
    $app->instance('config', new ConfigRepository([
        'app' => ['name' => 'ledger', 'env' => 'testing'],
        'logging' => [
            'default' => 'wiring_test',
            'channels' => ['wiring_test' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'name' => 'wiring_test']],
        ],
        'firefly' => ['logging' => ['structured' => $structured]],
    ]));
    // A bind() closure, not instance(): LogManager builds the handler with a non-empty $with (['level' => ...]),
    // which makes Container::resolve() skip the instances[] short-circuit — see CorrelationIdLogProcessorTest.
    $app->bind(TestHandler::class, static fn (): TestHandler => $handler);
    $app->singleton(ExceptionHandler::class, Handler::class);

    return $app;
}

function registerObservability(Application $app): void
{
    $app->register(new FireflyAutoConfigureServiceProvider($app));
    $app->register(new ObservabilityServiceProvider($app));
    $app->register(new ObservabilityWiringProvider($app));
}

/** The real Monolog logger behind the default channel of $app's LogManager. */
function wiringTestMonolog(Application $app): MonologLogger
{
    /** @var LogManager $log */
    $log = $app->make('log');
    $channel = $log->channel('wiring_test');
    expect($channel)->toBeInstanceOf(Logger::class);

    /** @var Logger $channel */
    $monolog = $channel->getLogger();
    expect($monolog)->toBeInstanceOf(MonologLogger::class);

    /** @var MonologLogger $monolog */
    return $monolog;
}

/**
 * @param  class-string  $processor
 */
function countProcessors(MonologLogger $monolog, string $processor): int
{
    return count(array_filter($monolog->getProcessors(), static fn (callable $p): bool => $p instanceof $processor));
}

it('runs at WiringPasses like the other observability passes', function () {
    $pass = new LogChannelWiringPass;

    expect($pass->phase())->toBe(BootPhase::WiringPasses)->and($pass->order())->toBe(0);
});

it('still refuses to boot when an exception report was the first log use and Laravel swallowed the refusal', function () {
    $app = bareLogApplication(new TestHandler, ['format' => 'json', 'channels' => ['reall']]);
    registerObservability($app);

    // Handler::report() is the first thing to resolve `log`: the afterResolving hook refuses, Handler's own
    // catch (Exception) swallows that, and what comes out is the DomainException being reported — with the
    // LogManager now cached WITHOUT the refusal ever having been seen by anyone.
    expect(fn () => $app->make(ExceptionHandler::class)->report(new DomainException('domain failure')))
        ->toThrow(DomainException::class, 'domain failure')
        ->and($app->resolved('log'))->toBeTrue();

    // The pass does not depend on that first resolution: boot itself refuses.
    expect(fn () => $app->boot())
        ->toThrow(ConfigurationException::class, "Unknown log channel 'reall' (firefly.logging.structured.channels)");
});

it('refuses to boot on an unknown format the same way', function () {
    $app = bareLogApplication(new TestHandler, ['format' => 'gelf']);
    registerObservability($app);

    // The shortest way to the same swallowed state: the hook's refusal caught by whoever resolved `log` first —
    // Container::resolve() had already cached the LogManager before firing it.
    $caught = null;
    try {
        $app->make('log');
    } catch (Throwable $e) {
        $caught = $e;
    }
    expect($caught)->toBeInstanceOf(ConfigurationException::class)
        ->and($app->resolved('log'))->toBeTrue();

    expect(fn () => $app->boot())
        ->toThrow(ConfigurationException::class, "Unknown structured log format 'gelf'");
});

it('wires a LogManager that was already resolved before the provider registered', function () {
    $handler = new TestHandler;
    $app = bareLogApplication($handler);
    // Laravel registers package providers alphabetically, so any earlier provider that logs from register()
    // resolves `log` before ObservabilityWiringProvider::register() installs its hook — the hook never fires.
    $app->make('log');
    registerObservability($app);

    $app->boot();

    $monolog = wiringTestMonolog($app);
    expect(countProcessors($monolog, CorrelationIdLogProcessor::class))->toBe(1)
        ->and(countProcessors($monolog, TraceContextLogProcessor::class))->toBe(1)
        ->and(countProcessors($monolog, ServiceContextLogProcessor::class))->toBe(1)
        ->and($handler->getFormatter())->toBeInstanceOf(JsonFormatter::class);

    // And a real write through it is the JSON line an aggregator ingests. The processors read Context through the
    // facade, which a bare Application never points at itself — set for the write, restored after.
    $previous = Facade::getFacadeApplication();
    Facade::setFacadeApplication($app);
    try {
        $monolog->warning('after boot', ['free' => '3%']);
    } finally {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($previous);
    }

    $records = $handler->getRecords();
    expect($records)->toHaveCount(1);
    $formatted = $records[0]->formatted;
    expect($formatted)->toBeString();

    /** @var array<string, mixed> $line */
    $line = json_decode(is_string($formatted) ? $formatted : '{}', true, 512, JSON_THROW_ON_ERROR);
    expect($line['message'])->toBe('after boot')
        ->and($line['channel'])->toBe('wiring_test')
        ->and($line['context'])->toBe(['free' => '3%'])
        ->and($line['extra'])->toMatchArray(['service_name' => 'ledger', 'service_environment' => 'testing']);
});

it('attaches once when the early hook and the pass both reach the same LogManager', function () {
    // The ordinary boot: nothing resolves `log` before the pass, whose own make('log') fires the hook first and
    // then finds the channel already wired. Neither path may stack a second copy of anything.
    $handler = new TestHandler;
    $app = bareLogApplication($handler);
    registerObservability($app);

    $app->boot();

    $monolog = wiringTestMonolog($app);
    expect(countProcessors($monolog, CorrelationIdLogProcessor::class))->toBe(1)
        ->and(countProcessors($monolog, TraceContextLogProcessor::class))->toBe(1)
        ->and(countProcessors($monolog, ServiceContextLogProcessor::class))->toBe(1)
        ->and($handler->getFormatter())->toBeInstanceOf(JsonFormatter::class);
});

it('leaves a default channel that logging.channels does not define to Laravel instead of building its emergency logger at boot', function () {
    // A bare application with no `logging` config at all (PackageBootTest's shape): the default falls back to
    // `stack`, which nothing defines. There is no real logger to put the processors on — LogManager::channel()
    // would hand back a throw-away emergency logger, writing an emergency line to storage/logs on every boot —
    // so the pass attaches nothing and the misconfiguration stays Laravel's to report on the first write.
    $app = new Application;
    $app->instance('config', new ConfigRepository(['firefly' => []]));
    registerObservability($app);

    $app->boot();

    /** @var LogManager $log */
    $log = $app->make('log');
    expect($log->getChannels())->toBe([]);
});

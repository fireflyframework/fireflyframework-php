<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Observability\Logging\Formatter\EcsFormatter;
use Firefly\Observability\Logging\ServiceContextLogProcessor;
use Firefly\Observability\Logging\StructuredLogging;
use Illuminate\Config\Repository as ConfigRepository;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Formatter\LogstashFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\TestHandler;
use Monolog\Handler\WhatFailureGroupHandler;
use Monolog\Logger as MonologLogger;

/**
 * @param  array<string, mixed>  $structured
 * @param  array<string, mixed>  $logging  Laravel's own logging.* keys
 */
function structuredLogging(array $structured, ?array $logging = null, string $appName = 'ledger'): StructuredLogging
{
    // The shape logging.php gives LogManager: `default` names a channel and `channels` defines the ones an
    // explicit `structured.channels` list may name (channels() validates against exactly that map).
    $logging ??= ['default' => 'stack', 'channels' => ['stack' => ['driver' => 'stack'], 'single' => ['driver' => 'single'], 'stderr' => ['driver' => 'monolog']]];

    return new StructuredLogging(new Config(new ConfigRepository([
        'app' => ['name' => $appName, 'env' => 'staging'],
        'logging' => $logging,
        'firefly' => ['logging' => ['structured' => $structured], 'observability' => ['tracing' => ['service-name' => '']]],
    ])));
}

it('is off by default: no format, no formatter, and the default channel is the only target', function () {
    $structured = structuredLogging([]);

    expect($structured->format())->toBe('')
        ->and($structured->formatter())->toBeNull()
        ->and($structured->channels())->toBe(['stack'])
        ->and($structured->serviceName())->toBe('ledger')
        ->and($structured->environment())->toBe('staging');
});

it('selects the formatter by name and honours an explicit channel list', function () {
    expect(structuredLogging(['format' => 'json'])->formatter())->toBeInstanceOf(JsonFormatter::class)
        ->and(structuredLogging(['format' => 'ecs'])->formatter())->toBeInstanceOf(EcsFormatter::class)
        ->and(structuredLogging(['format' => 'logstash'])->formatter())->toBeInstanceOf(LogstashFormatter::class)
        ->and(structuredLogging(['format' => 'json', 'channels' => ['single', 'stderr']])->channels())->toBe(['single', 'stderr']);
});

it('rejects an unknown format at boot', function () {
    expect(fn () => structuredLogging(['format' => 'gelf'])->format())
        ->toThrow(ConfigurationException::class, "Unknown structured log format 'gelf'");
});

it('rejects a channel that logging.channels does not define', function () {
    // LogManager::channel() never throws for this — it hands back a throw-away emergency logger and the real
    // channel keeps plain text with no ids — so the typo has to be refused here, where format() refuses its own.
    expect(fn () => structuredLogging(['format' => 'json', 'channels' => ['stack', 'reall']])->channels())
        ->toThrow(ConfigurationException::class, "Unknown log channel 'reall' (firefly.logging.structured.channels)");

    // Regardless of the format: the id processors follow the same list, so a typo is wrong with plain text too.
    expect(fn () => structuredLogging(['channels' => ['reall']])->channels())
        ->toThrow(ConfigurationException::class, "Unknown log channel 'reall'");

    // A null entry is "not defined" to LogManager::resolve() (is_null), whatever Repository::has() says about the key.
    expect(fn () => structuredLogging(['channels' => ['stack']], ['default' => 'stack', 'channels' => ['stack' => null]])->channels())
        ->toThrow(ConfigurationException::class, "Unknown log channel 'stack'");

    // The default fallback is logging.default's business (LogManager's emergency logger is loud about it), not this key's.
    expect(structuredLogging([], ['default' => 'nowhere', 'channels' => []])->channels())->toBe(['nowhere']);
});

it('tells a defined channel from an undefined or null one the way LogManager::resolve() does', function () {
    $structured = structuredLogging([], ['default' => 'stack', 'channels' => ['stack' => ['driver' => 'stack'], 'gone' => null]]);

    expect($structured->defines('stack'))->toBeTrue()
        ->and($structured->defines('gone'))->toBeFalse()
        ->and($structured->defines('reall'))->toBeFalse();
});

it('applies the formatter to every formattable handler in place and pushes the service-context processor', function () {
    $test = new TestHandler;
    $test->setFormatter(new LineFormatter);
    $monolog = new MonologLogger('stack', [$test, new NullHandler]);

    structuredLogging(['format' => 'json'])->apply($monolog);

    expect($test->getFormatter())->toBeInstanceOf(JsonFormatter::class)
        ->and($monolog->getHandlers())->toHaveCount(2)
        ->and($monolog->getProcessors()[0])->toBeInstanceOf(ServiceContextLogProcessor::class);

    // Off means untouched: the LineFormatter an application configured stays.
    $untouched = new TestHandler;
    $untouched->setFormatter(new LineFormatter);
    $plain = new MonologLogger('stack', [$untouched]);
    structuredLogging([])->apply($plain);
    expect($untouched->getFormatter())->toBeInstanceOf(LineFormatter::class)->and($plain->getProcessors())->toBe([]);
});

it('applies once to a logger it reaches twice: one service-context processor, the formatter simply set again', function () {
    // LogChannelWiring reaches a logger twice in the ordinary boot (the early hook, then the pass), and a stack
    // built after a listed member inherits that member's processors (LogManager::createStackDriver copies them)
    // — so apply() must be safe to repeat: the processor only where none is, the formatter every time.
    $test = new TestHandler;
    $test->setFormatter(new LineFormatter);
    $monolog = new MonologLogger('stack', [$test]);
    $structured = structuredLogging(['format' => 'json']);

    $structured->apply($monolog);
    $structured->apply($monolog);

    expect(array_filter($monolog->getProcessors(), static fn (callable $p): bool => $p instanceof ServiceContextLogProcessor))->toHaveCount(1)
        ->and($test->getFormatter())->toBeInstanceOf(JsonFormatter::class)
        ->and(StructuredLogging::hasProcessor($monolog, ServiceContextLogProcessor::class))->toBeTrue()
        ->and(StructuredLogging::hasProcessor(new MonologLogger('bare'), ServiceContextLogProcessor::class))->toBeFalse();

    // A handler added to that logger later (a stack member wired after the stack) is formatted by the next pass.
    $late = new TestHandler;
    $late->setFormatter(new LineFormatter);
    $monolog->pushHandler($late);
    $structured->apply($monolog);
    expect($late->getFormatter())->toBeInstanceOf(JsonFormatter::class);
});

it('reaches the members of a group handler, which Laravel wraps an ignore_exceptions stack in, without replacing it', function () {
    // Monolog's GroupHandler family declares setFormatter() (forwarding to its formattable members) but does NOT
    // implement FormattableHandlerInterface, and LogManager::createStackDriver wraps a stack's members in a
    // WhatFailureGroupHandler when `ignore_exceptions` is true — so an instanceof-the-interface check alone
    // silently leaves every member on LineFormatter.
    $member = new TestHandler;
    $member->setFormatter(new LineFormatter);
    $group = new WhatFailureGroupHandler([$member]);
    $monolog = new MonologLogger('stack', [$group]);

    structuredLogging(['format' => 'json'])->apply($monolog);

    expect($member->getFormatter())->toBeInstanceOf(JsonFormatter::class)
        ->and($monolog->getHandlers())->toBe([$group]);
});

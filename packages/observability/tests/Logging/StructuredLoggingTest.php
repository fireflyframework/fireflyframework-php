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
use Monolog\Logger as MonologLogger;

/**
 * @param  array<string, mixed>  $structured
 * @param  array<string, mixed>  $logging  Laravel's own logging.* keys
 */
function structuredLogging(array $structured, array $logging = ['default' => 'stack'], string $appName = 'ledger'): StructuredLogging
{
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

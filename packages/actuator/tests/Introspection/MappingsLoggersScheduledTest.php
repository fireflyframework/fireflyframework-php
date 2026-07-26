<?php

declare(strict_types=1);

use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Actuator\Introspection\LoggersEndpoint;
use Firefly\Actuator\Introspection\MappingsEndpoint;
use Firefly\Actuator\Introspection\ScheduledTasksEndpoint;
use Firefly\Scheduling\Schedule\ScheduledDescriptor;
use Firefly\Scheduling\Schedule\ScheduledManifest;
use Firefly\Web\Route\RouteDescriptor;
use Firefly\Web\Route\RouteManifest;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Log\Logger as LaravelLogger;
use Illuminate\Log\LogManager;
use Monolog\Handler\AbstractHandler;
use Monolog\Level;
use Monolog\Logger as Monolog;

/**
 * EndpointResponse::$body is `array<mixed>|string` (text endpoints use string) — narrow to array via
 * real control flow (never a suppressing @var/assert() override), same idiom as
 * IntrospectionEndpointsTest.php's introspectionJsonBody() helper. Named distinctly so this file has
 * no top-level-function collision with that one when Pest loads both into the same process for a
 * whole-package run.
 *
 * @return array<mixed>
 */
function mlsJsonBody(?EndpointResponse $response): array
{
    if ($response === null) {
        throw new RuntimeException('Expected a non-null EndpointResponse.');
    }

    if (! is_array($response->body)) {
        throw new RuntimeException('Expected a JSON (array) response body.');
    }

    return $response->body;
}

/**
 * Walks a decoded JSON body by a fixed key/index path, verifying each intermediate hop is a real
 * array before descending into it — real runtime narrowing (never a suppressing @var override), so
 * PHPStan (level max) can follow along even though every array value here is `mixed`. Mirrors
 * IntrospectionEndpointsTest.php's introspectionRows() reasoning, generalised to an arbitrary depth
 * instead of one fixed "rows under one key" shape.
 *
 * @param  array<mixed>  $body
 * @param  list<int|string>  $path
 */
function mlsGet(array $body, array $path): mixed
{
    $current = $body;
    foreach ($path as $key) {
        if (! is_array($current) || ! array_key_exists($key, $current)) {
            throw new RuntimeException('Expected path ['.implode('.', array_map(strval(...), $path)).'] to exist.');
        }
        $current = $current[$key];
    }

    return $current;
}

/**
 * Builds a real, fully-wired `Illuminate\Log\LogManager` from a real `Illuminate\Foundation\
 * Application` (its constructor always registers Illuminate\Log\LogServiceProvider, which binds
 * 'log'). A bare Pest test in this package has no bootstrapped `app()` container (no TestCase/
 * testbench wiring), so the brief's original `app('log')` sketch would resolve against a null/absent
 * global container; this sidesteps that without a global-state dependency or an orchestra/testbench
 * addition, matching the PackageBootTest idiom already used elsewhere in this package.
 */
function mlsLogManager(Repository $config): LogManager
{
    $app = new Application;
    $app->instance('config', $config);

    /** @var LogManager $logs */
    $logs = $app->make('log');

    return $logs;
}

it('lists compiled routes on /mappings', function () {
    $manifest = new RouteManifest([
        new RouteDescriptor('GET', '/balances/{id}', 'App\\BalanceController', 'show', 200, 'balances.show', []),
    ]);

    $body = mlsJsonBody((new MappingsEndpoint($manifest))->handle(new EndpointRequest('GET', [])));

    expect(mlsGet($body, ['mappings', 0]))->toMatchArray([
        'httpMethod' => 'GET',
        'path' => '/balances/{id}',
        'handler' => 'App\\BalanceController@show',
        'name' => 'balances.show',
    ]);
});

it('lists scheduled tasks on /scheduledtasks', function () {
    $manifest = new ScheduledManifest([
        new ScheduledDescriptor('App\\Reports', 'nightly', cron: '0 0 * * *'),
    ]);

    $body = mlsJsonBody((new ScheduledTasksEndpoint($manifest))->handle(new EndpointRequest('GET', [])));

    expect(mlsGet($body, ['tasks', 0]))->toMatchArray(['runnable' => 'App\\Reports@nightly', 'cron' => '0 0 * * *']);
});

it('degrades to an empty list on /scheduledtasks when no #[Scheduled] methods are compiled', function () {
    // The "scheduling absent" degradation is only exercisable via an EMPTY compiled manifest in this
    // monorepo — firefly/actuator's own composer.json hard-requires firefly/scheduling, so
    // ScheduledManifest is always on the classpath (see the #[ConditionalOnClass] docblock on
    // ScheduledTasksEndpoint for the other, package-absence sense of "degrades gracefully").
    $body = mlsJsonBody((new ScheduledTasksEndpoint(new ScheduledManifest([])))->handle(new EndpointRequest('GET', [])));

    expect($body['tasks'])->toBe([]);
});

it('reports configured logger levels on GET /loggers', function () {
    $config = new Repository(['logging' => ['channels' => ['stack' => ['level' => 'debug'], 'single' => ['level' => 'warning']]]]);
    $logs = mlsLogManager($config);

    $body = mlsJsonBody((new LoggersEndpoint($logs, $config))->handle(new EndpointRequest('GET', [])));

    expect(mlsGet($body, ['loggers', 'stack', 'configuredLevel']))->toBe('DEBUG')
        ->and(mlsGet($body, ['loggers', 'single', 'configuredLevel']))->toBe('WARNING');
});

it('sets a channel level at runtime on POST /loggers/{name}', function () {
    $config = new Repository([
        'logging' => [
            'default' => 'single',
            'channels' => [
                'single' => ['driver' => 'single', 'path' => sys_get_temp_dir().'/firefly-loggers-test.log', 'level' => 'debug'],
            ],
        ],
    ]);
    $logs = mlsLogManager($config);

    $body = mlsJsonBody((new LoggersEndpoint($logs, $config))->handle(new EndpointRequest('POST', ['single'], body: ['level' => 'warning'])));

    expect($body)->toMatchArray(['name' => 'single', 'configuredLevel' => 'WARNING']);

    // Prove the level was ACTUALLY applied to the live Monolog handler(s), not just echoed back —
    // real instanceof narrowing throughout (not a Pest ->toBeInstanceOf() assertion, which does not
    // narrow the static type for PHPStan), same idiom LoggersEndpoint::setLevel() itself uses.
    $channel = $logs->channel('single');
    if (! $channel instanceof LaravelLogger) {
        throw new RuntimeException('Expected an Illuminate\\Log\\Logger channel.');
    }

    $monolog = $channel->getLogger();
    if (! $monolog instanceof Monolog) {
        throw new RuntimeException('Expected the channel to wrap a Monolog\\Logger.');
    }

    $handlers = $monolog->getHandlers();
    expect($handlers)->not->toBeEmpty();

    foreach ($handlers as $handler) {
        if (! $handler instanceof AbstractHandler) {
            throw new RuntimeException('Expected a Monolog\\Handler\\AbstractHandler.');
        }

        expect($handler->getLevel())->toBe(Level::Warning);
    }
});

it('returns null (404) for an unknown level on POST /loggers/{name}', function () {
    $config = new Repository(['logging' => ['channels' => ['stack' => ['level' => 'debug']]]]);
    $logs = mlsLogManager($config);

    $response = (new LoggersEndpoint($logs, $config))->handle(new EndpointRequest('POST', ['stack'], body: ['level' => 'not-a-level']));

    expect($response)->toBeNull();
});

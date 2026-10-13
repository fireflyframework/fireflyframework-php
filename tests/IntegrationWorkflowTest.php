<?php

// tests/IntegrationWorkflowTest.php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * The integration lanes are part of the gate (PROGRAM.md rule 7), so their SHAPE is held here the way
 * ReleaseWorkflowTest holds release.yml: a lane that loses its health check starts tests against a server that is
 * still booting, a lane that exports a second DSN quietly runs another lane's suite, and a compose file that drifts
 * from CI makes "green locally" mean something else. The helpers are prefixed (`integrationYaml…`) because Pest
 * files share one global function table with ReleaseWorkflowTest's `asYamlMap()`.
 *
 * @return array<string, mixed>
 */
function integrationYamlMap(mixed $value, string $what): array
{
    if (! is_array($value) || array_is_list($value) && $value !== []) {
        throw new RuntimeException("{$what} is not a YAML mapping.");
    }

    /** @var array<string, mixed> $value */
    return $value;
}

/** @return list<mixed> */
function integrationYamlList(mixed $value, string $what): array
{
    if (! is_array($value) || ! array_is_list($value)) {
        throw new RuntimeException("{$what} is not a YAML sequence.");
    }

    return $value;
}

/** @return array<string, mixed> */
function integrationCiJobs(): array
{
    $ci = integrationYamlMap(Yaml::parseFile(dirname(__DIR__).'/.github/workflows/ci.yml'), 'ci.yml');

    return integrationYamlMap($ci['jobs'] ?? null, 'ci.yml jobs');
}

function integrationYamlString(mixed $value): string
{
    return is_scalar($value) ? (string) $value : '';
}

/**
 * The `run:` text of every step of a job, joined.
 *
 * @param  array<string, mixed>  $job
 */
function integrationRunText(array $job): string
{
    $runs = '';
    foreach (integrationYamlList($job['steps'] ?? null, 'steps') as $step) {
        $step = integrationYamlMap($step, 'step');
        $runs .= is_string($step['run'] ?? null) ? $step['run']."\n" : '';
    }

    return $runs;
}

it('runs the integration group through one composer script with enough memory to collect it', function () {
    /** @var array{scripts: array<string, string|list<string>>} $composer */
    $composer = json_decode((string) file_get_contents(dirname(__DIR__).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['test:integration'] ?? null)->toBe('@php -d memory_limit=1G vendor/bin/pest --group integration');
});

it('runs one integration lane per database server, each with a healthy service and only its own DSN', function () {
    $job = integrationYamlMap(integrationCiJobs()['integration'] ?? null, 'jobs.integration');
    $matrix = integrationYamlMap(integrationYamlMap($job['strategy'] ?? null, 'strategy')['matrix'] ?? null, 'matrix');

    $lanes = [];
    foreach (integrationYamlList($matrix['include'] ?? null, 'matrix.include') as $entry) {
        $entry = integrationYamlMap($entry, 'matrix entry');
        $lanes[integrationYamlString($entry['lane'] ?? null)] = [integrationYamlString($entry['image'] ?? null), integrationYamlString($entry['dsn-variable'] ?? null)];
        expect(integrationYamlString($entry['health'] ?? null))->not->toBe('');
    }

    expect($lanes)->toBe([
        'pgsql' => ['postgres:16', 'FIREFLY_PG_DSN'],
        'mysql' => ['mysql:8.4', 'FIREFLY_MYSQL_DSN'],
        'mariadb' => ['mariadb:11', 'FIREFLY_MARIADB_DSN'],
        'sqlsrv' => ['mcr.microsoft.com/mssql/server:2022-latest', 'FIREFLY_SQLSRV_DSN'],
    ]);

    $service = integrationYamlMap(integrationYamlMap($job['services'] ?? null, 'services')['database'] ?? null, 'services.database');
    expect($service['image'] ?? null)->toBe('${{ matrix.image }}')
        ->and(integrationYamlString($service['options'] ?? null))->toContain('--health-cmd');

    $run = integrationRunText($job);
    expect($run)->toContain("export \${{ matrix.dsn-variable }}='\${{ matrix.dsn }}'")
        ->and($run)->toContain('composer test:integration')
        ->and(substr_count($run, 'export '))->toBe(1)
        ->and(json_encode($job, JSON_THROW_ON_ERROR))->not->toContain('FIREFLY_RABBITMQ_DSN');
});

it('runs the brokers lane against RabbitMQ, Kafka (with ext-rdkafka) and Redis, each health-checked', function () {
    $job = integrationYamlMap(integrationCiJobs()['brokers'] ?? null, 'jobs.brokers');
    $services = integrationYamlMap($job['services'] ?? null, 'services');

    expect(array_keys($services))->toBe(['rabbitmq', 'kafka', 'redis']);
    foreach ($services as $name => $service) {
        expect(integrationYamlString(integrationYamlMap($service, $name)['options'] ?? null))->toContain('--health-cmd');
    }

    $env = [];
    foreach (integrationYamlList($job['steps'] ?? null, 'steps') as $step) {
        $step = integrationYamlMap($step, 'step');
        if (str_contains(integrationYamlString($step['run'] ?? null), 'composer test:integration')) {
            $env = integrationYamlMap($step['env'] ?? null, 'env');
        }
    }

    expect(array_keys($env))->toBe(['FIREFLY_INTEGRATION_LANE', 'FIREFLY_RABBITMQ_DSN', 'FIREFLY_KAFKA_BROKERS', 'FIREFLY_REDIS_URL'])
        ->and(json_encode($job, JSON_THROW_ON_ERROR))->toContain('rdkafka');
});

it('mirrors every CI image in docker-compose.integration.yml', function () {
    $compose = integrationYamlMap(Yaml::parseFile(dirname(__DIR__).'/docker-compose.integration.yml'), 'compose');
    $images = [];
    foreach (integrationYamlMap($compose['services'] ?? null, 'services') as $name => $service) {
        $images[$name] = integrationYamlString(integrationYamlMap($service, $name)['image'] ?? null);
    }

    $jobs = integrationCiJobs();
    $strategy = integrationYamlMap(integrationYamlMap($jobs['integration'] ?? null, 'integration')['strategy'] ?? null, 'strategy');
    $ci = [];
    foreach (integrationYamlList(integrationYamlMap($strategy['matrix'] ?? null, 'matrix')['include'] ?? null, 'include') as $entry) {
        $ci[] = integrationYamlString(integrationYamlMap($entry, 'entry')['image'] ?? null);
    }
    foreach (integrationYamlMap(integrationYamlMap($jobs['brokers'] ?? null, 'brokers')['services'] ?? null, 'services') as $name => $service) {
        $ci[] = integrationYamlString(integrationYamlMap($service, $name)['image'] ?? null);
    }

    sort($ci);
    $local = array_values($images);
    sort($local);

    expect($local)->toBe($ci);
});

it('keeps the compose mirror out of the library archive', function () {
    // The compose file is a contributor tool like phpunit.xml.dist and testbench.yaml; a consumer installing
    // fireflyframework/larafly must not find a set of database containers in its vendor directory.
    $attributes = (string) file_get_contents(dirname(__DIR__).'/.gitattributes');

    expect($attributes)->toMatch('#^/docker-compose\.integration\.yml\s+export-ignore$#m');
});

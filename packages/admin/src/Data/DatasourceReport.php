<?php

declare(strict_types=1);

namespace Firefly\Admin\Data;

use Firefly\Actuator\Introspection\SensitiveValueMasker;
use Firefly\Config\Config;
use Firefly\Data\Transaction\TransactionalManifest;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use PDO;
use Throwable;

/**
 * What the application's data layer is actually configured as, and what it is actually doing.
 *
 * FOUR QUESTIONS AN OPERATOR ASKS AT 3AM, and none of them was answerable from this dashboard before.
 *
 *   WHICH DATABASE AM I TALKING TO? A connection's driver, host, port and database name — the first thing
 *   anyone checks when the data looks wrong, and the thing most likely to differ from what you assumed.
 *
 *   IS IT UP? A connection is configured until you use it. `probe()` opens each one and asks for its server
 *   version, which is the difference between "configured correctly" and "working" — and is exactly the
 *   distinction a config dump cannot make.
 *
 *   WHAT DOES POOLING MEAN HERE? PHP has no connection pool, and pretending otherwise with a "pool size"
 *   gauge would be inventing a number. What exists is PDO's PERSISTENT flag, which keeps a connection open
 *   on the php-fpm worker between requests, and it is reported as what it is — with the caveat that under
 *   php-fpm the pool's size is the worker count, decided by the process manager and not by the framework.
 *
 *   WHAT DID #[Transactional] COMPILE TO? The manifest is the framework's own data configuration — which
 *   methods are proxied, and with which propagation, isolation, timeout and connection. It exists as a
 *   compiled artifact nobody could read without opening bootstrap/cache; the point of a dashboard is that
 *   you do not have to.
 *
 * EVERY CONFIG VALUE GOES THROUGH SensitiveValueMasker — the same masker the actuator's env endpoint uses,
 * so `password` is `******` here for the same reason and by the same rule. A page that dumped a connection
 * array verbatim would put the database password on a URL the dashboard's own gate is the only guard for.
 */
final class DatasourceReport
{
    /**
     * @param  array<string, mixed>  $database  Laravel's `database` config, as written
     */
    public function __construct(
        private readonly ?ConnectionResolverInterface $connections,
        private readonly ?TransactionalManifest $manifest,
        private readonly array $database,
        private readonly bool $probeEnabled = true,
    ) {}

    public static function forContainer(Container $container): self
    {
        $resolver = null;
        try {
            $resolver = $container->make(DatabaseManager::class);
        } catch (Throwable) {
            // No database manager bound at all — a perfectly legal LaraFly application that never installed
            // illuminate/database. The page then says so rather than failing to render.
        }

        $manifest = null;
        try {
            $manifest = $container->make(TransactionalManifest::class);
        } catch (Throwable) {
        }

        $config = $container->make(Config::class);

        /** @var array<string, mixed> $database */
        $database = $config->array('database', []);

        return new self($resolver, $manifest, $database, $config->bool('firefly.admin.datasource.probe', true));
    }

    /** Whether opening a connection to ask what it is, is permitted at all. */
    public function probeEnabled(): bool
    {
        return $this->probeEnabled;
    }

    public function available(): bool
    {
        return $this->connections !== null;
    }

    public function defaultConnection(): string
    {
        $default = $this->database['default'] ?? null;

        return is_string($default) ? $default : '';
    }

    /**
     * Every configured connection, masked, with the handful of settings that actually matter pulled to the
     * front and the rest kept underneath.
     *
     * @return list<array{name: string, default: bool, driver: string, target: string, summary: array<string, string>, options: array<string, string>}>
     */
    public function connections(): array
    {
        $configured = $this->database['connections'] ?? null;

        if (! is_array($configured)) {
            return [];
        }

        $rows = [];
        foreach ($configured as $name => $settings) {
            if (! is_array($settings)) {
                continue;
            }

            /** @var array<string, mixed> $masked */
            $masked = SensitiveValueMasker::mask($settings);
            $driver = is_string($masked['driver'] ?? null) ? $masked['driver'] : 'unknown';

            $rows[] = [
                'name' => (string) $name,
                'default' => (string) $name === $this->defaultConnection(),
                'driver' => $driver,
                'target' => $this->target($driver, $masked),
                'summary' => $this->summary($masked),
                'options' => $this->options($masked),
            ];
        }

        return $rows;
    }

    /**
     * Opens a connection and asks it what it is.
     *
     * Deliberately separate from connections(): reading configuration is free and cannot fail, while opening
     * a socket can hang against a firewalled host. Keeping them apart means the page renders its
     * configuration half even when a connection is down — which is precisely the moment someone is looking
     * at it.
     *
     * @return array{up: bool, detail: string, version: string}
     */
    public function probe(string $name): array
    {
        if ($this->connections === null) {
            return ['up' => false, 'detail' => 'No database manager is bound.', 'version' => ''];
        }

        if (! $this->probeEnabled) {
            return ['up' => false, 'detail' => 'Probing is switched off (firefly.admin.datasource.probe).', 'version' => ''];
        }

        try {
            $connection = $this->connections->connection($name);

            // Typed as the narrow ConnectionInterface, which does not promise a PDO — a connection may be a
            // driver with none. `selectOne` is on the interface and forces the socket open either way, so it
            // is the honest way to ask "does this answer"; the PDO version string is a bonus taken only when
            // there is a PDO to take it from.
            $connection->selectOne('select 1');

            $version = '';
            if ($connection instanceof Connection) {
                $attribute = $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
                $version = is_scalar($attribute) ? (string) $attribute : '';
            }

            return ['up' => true, 'detail' => 'Connected.', 'version' => $version];
        } catch (Throwable $e) {
            // The message is shown as-is: this page is already behind the dashboard's gate, and a connection
            // error whose text is withheld ("could not connect") is the single least useful thing an
            // operator can be told.
            return ['up' => false, 'detail' => $e->getMessage(), 'version' => ''];
        }
    }

    /**
     * The compiled #[Transactional] manifest, flattened to one row per proxied METHOD.
     *
     * @return list<array{class: string, method: string, propagation: string, isolation: string, readOnly: bool, timeout: string, connection: string}>
     */
    public function transactionalMethods(): array
    {
        if ($this->manifest === null) {
            return [];
        }

        $rows = [];
        foreach ($this->manifest->all() as $class => $proxy) {
            foreach ($proxy['methods'] as $method => $descriptor) {
                $rows[] = [
                    'class' => $class,
                    'method' => $method,
                    'propagation' => $descriptor['propagation'],
                    'isolation' => $descriptor['isolation'],
                    'readOnly' => $descriptor['readOnly'],
                    'timeout' => $descriptor['timeout'] === null ? '—' : $descriptor['timeout'].'s',
                    'connection' => $descriptor['connection'] ?? '(default)',
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => [$a['class'], $a['method']] <=> [$b['class'], $b['method']]);

        return $rows;
    }

    /**
     * Whether PDO is told to keep connections open between requests, per connection.
     *
     * @return list<array{name: string, persistent: bool, note: string}>
     */
    public function pooling(): array
    {
        $rows = [];

        foreach ($this->connections() as $connection) {
            $persistent = ($connection['options']['ATTR_PERSISTENT'] ?? 'false') === 'true';

            $rows[] = [
                'name' => $connection['name'],
                'persistent' => $persistent,
                'note' => $persistent
                    ? 'PDO keeps this connection open on the worker between requests.'
                    : 'A new connection is opened per request.',
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function target(string $driver, array $settings): string
    {
        $string = static fn (string $key): string => is_scalar($settings[$key] ?? null) ? (string) $settings[$key] : '';

        if ($driver === 'sqlite') {
            $database = $string('database');

            return $database === '' ? '(unset)' : $database;
        }

        $host = $string('host');
        $port = $string('port');
        $database = $string('database');

        $target = $host === '' ? '' : $host.($port === '' ? '' : ':'.$port);

        return trim($target.($database === '' ? '' : '/'.$database), '/') ?: '(unset)';
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, string>
     */
    private function summary(array $settings): array
    {
        $summary = [];

        foreach (['host', 'port', 'database', 'username', 'password', 'charset', 'collation', 'prefix', 'search_path', 'schema', 'sslmode'] as $key) {
            if (! array_key_exists($key, $settings)) {
                continue;
            }
            $summary[$key] = $this->scalar($settings[$key]);
        }

        return $summary;
    }

    /**
     * The PDO attribute options, with the numeric PDO:: constants translated back into the names a person
     * wrote in their config file. A raw `{"12": true}` is technically the truth and tells nobody anything.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, string>
     */
    private function options(array $settings): array
    {
        $options = $settings['options'] ?? null;

        if (! is_array($options)) {
            return [];
        }

        $names = [
            PDO::ATTR_PERSISTENT => 'ATTR_PERSISTENT',
            PDO::ATTR_TIMEOUT => 'ATTR_TIMEOUT',
            PDO::ATTR_EMULATE_PREPARES => 'ATTR_EMULATE_PREPARES',
            PDO::ATTR_ERRMODE => 'ATTR_ERRMODE',
            PDO::ATTR_CASE => 'ATTR_CASE',
            PDO::ATTR_STRINGIFY_FETCHES => 'ATTR_STRINGIFY_FETCHES',
            PDO::ATTR_DEFAULT_FETCH_MODE => 'ATTR_DEFAULT_FETCH_MODE',
        ];

        $translated = [];
        foreach ($options as $key => $value) {
            $name = is_int($key) && isset($names[$key]) ? $names[$key] : (string) $key;
            $translated[$name] = $this->scalar($value);
        }

        ksort($translated);

        return $translated;
    }

    private function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => json_encode($value) ?: '(unencodable)',
        };
    }
}

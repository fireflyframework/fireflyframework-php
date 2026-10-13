<?php

declare(strict_types=1);

namespace Firefly\Testing\Integration;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use InvalidArgumentException;

/**
 * The ONE place an integration test turns a `FIREFLY_*` environment variable into something it can connect to.
 *
 * Seven test suites used to carry their own `explode(';')` parser, each with its own defaults, and each failed the
 * same silent way: a value it could not read became `127.0.0.1` and the backend's default port, so a typo — or a
 * perfectly valid URL-form DSN — surfaced as a connection refused against a server nobody had configured. This class
 * reads both forms the program allows (`host=…;port=…;dbname=…;user=…;password=…` and `scheme://user:pass@host:port/
 * path?extra=…`), refuses anything else with the variable's value in the message, and treats a blank variable exactly
 * like an unset one, so a CI lane can export only its own variables and every other suite skips.
 *
 * A lane is a backend the integration matrix knows: the four servers plus `sqlite`, which needs no variable and runs
 * on a temporary FILE (never `:memory:`) so a second OS process can race the first over the same database.
 */
final class IntegrationDsn
{
    public const string PGSQL = 'FIREFLY_PG_DSN';

    public const string MYSQL = 'FIREFLY_MYSQL_DSN';

    public const string MARIADB = 'FIREFLY_MARIADB_DSN';

    public const string SQLSRV = 'FIREFLY_SQLSRV_DSN';

    public const string RABBITMQ = 'FIREFLY_RABBITMQ_DSN';

    public const string KAFKA = 'FIREFLY_KAFKA_BROKERS';

    public const string REDIS = 'FIREFLY_REDIS_URL';

    /** Set by CI to the lane a job runs; read by tests/Integration/IntegrationLaneTest.php. */
    public const string LANE = 'FIREFLY_INTEGRATION_LANE';

    /** @var array<string, array{driver: string, variable: string|null, extension: string}> */
    public const array DATABASES = [
        'pgsql' => ['driver' => 'pgsql', 'variable' => self::PGSQL, 'extension' => 'pdo_pgsql'],
        'mysql' => ['driver' => 'mysql', 'variable' => self::MYSQL, 'extension' => 'pdo_mysql'],
        'mariadb' => ['driver' => 'mariadb', 'variable' => self::MARIADB, 'extension' => 'pdo_mysql'],
        'sqlsrv' => ['driver' => 'sqlsrv', 'variable' => self::SQLSRV, 'extension' => 'pdo_sqlsrv'],
        'sqlite' => ['driver' => 'sqlite', 'variable' => null, 'extension' => 'pdo_sqlite'],
    ];

    /** @var array<string, int> */
    private const array DEFAULT_PORTS = ['pgsql' => 5432, 'mysql' => 3306, 'mariadb' => 3306, 'sqlsrv' => 1433];

    /** @var list<string> the only keys a URL DSN's query string may set; host, port, user and password come from the authority */
    private const array URL_QUERY_EXTRAS = ['search_path', 'encrypt', 'trust_server_certificate', 'sslmode', 'charset', 'vhost'];

    private static ?string $sqliteDirectory = null;

    /** @return list<string> */
    public static function lanes(): array
    {
        return array_keys(self::DATABASES);
    }

    public static function value(string $variable): ?string
    {
        $value = getenv($variable);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public static function missing(string $variable): bool
    {
        return self::value($variable) === null;
    }

    /**
     * @return array<string, string>
     */
    public static function parse(string $dsn): array
    {
        $dsn = trim($dsn);

        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $dsn) === 1) {
            return self::parseUrl($dsn);
        }

        $parts = [];
        foreach (explode(';', $dsn) as $position => $pair) {
            if (trim($pair) === '') {
                continue;
            }
            if (! str_contains($pair, '=')) {
                // Never echo the DSN or the segment: either may be (part of) a password.
                throw new InvalidArgumentException(sprintf('Cannot read the DSN: segment %d is not a key=value pair (expected host=…;port=…;dbname=…;user=…;password=… or scheme://user:pass@host:port/db).', $position + 1));
            }
            [$key, $value] = explode('=', $pair, 2);
            $parts[strtolower(trim($key))] = trim($value);
        }

        if (($parts['host'] ?? '') === '') {
            throw new InvalidArgumentException('Cannot read the DSN: it names no host (expected host=…;port=…;dbname=…;user=…;password=…).');
        }

        return $parts;
    }

    /**
     * @return array<string, string>|null
     */
    public static function parts(string $variable): ?array
    {
        $value = self::value($variable);

        return $value === null ? null : self::parse($value);
    }

    /** Why $lane cannot run here, or null when it can. */
    public static function skipReason(string $lane): ?string
    {
        $database = self::DATABASES[$lane] ?? throw new InvalidArgumentException(sprintf('Unknown integration lane [%s]; known: %s.', $lane, implode(', ', self::lanes())));

        if (! extension_loaded($database['extension'])) {
            return sprintf('requires ext-%s for the %s integration lane', $database['extension'], $lane);
        }

        if ($database['variable'] !== null && self::missing($database['variable'])) {
            return sprintf('Set %s (host=…;port=…;dbname=…;user=…;password=…) to run the %s integration lane.', $database['variable'], $lane);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function connectionConfig(string $lane, array $overrides = []): array
    {
        $database = self::DATABASES[$lane] ?? throw new InvalidArgumentException(sprintf('Unknown integration lane [%s].', $lane));

        if ($database['variable'] === null) {
            $path = $overrides['database'] ?? self::sqlitePath();

            return array_replace(self::sqlite(is_string($path) ? $path : self::sqlitePath()), $overrides);
        }

        return self::connectionConfigFor($database['driver'], $database['variable'], $overrides);
    }

    /**
     * A config for $driver from the DSN in $variable — the form a suite uses when it deliberately speaks one driver to
     * another lane's server (feature-flags runs its `mysql` driver against the MariaDB server).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function connectionConfigFor(string $driver, string $variable, array $overrides = []): array
    {
        $parts = self::parts($variable) ?? throw new InvalidArgumentException(sprintf('%s is not set.', $variable));

        $config = [
            'driver' => $driver,
            'host' => $parts['host'],
            'port' => (int) ($parts['port'] ?? self::DEFAULT_PORTS[$driver] ?? 0),
            'database' => $parts['dbname'] ?? $parts['database'] ?? 'firefly',
            'username' => $parts['user'] ?? $parts['username'] ?? '',
            'password' => $parts['password'] ?? '',
            'prefix' => '',
        ];

        $config = match ($driver) {
            'pgsql' => [...$config, 'charset' => 'utf8', 'search_path' => $parts['search_path'] ?? 'public'],
            'mysql', 'mariadb' => [...$config, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
            'sqlsrv' => [...$config, 'charset' => 'utf8', 'encrypt' => $parts['encrypt'] ?? 'yes', 'trust_server_certificate' => $parts['trust_server_certificate'] ?? 'false'],
            default => $config,
        };

        return array_replace($config, $overrides);
    }

    /**
     * A sqlite connection config over a FILE, created when missing (Laravel refuses a sqlite path that does not
     * exist), in WAL mode with a busy timeout, so two OS processes can write it concurrently without SQLITE_BUSY.
     * The file is created exclusively (O_EXCL, mode 0600) and a symlink at $path is refused, so a pre-planted link
     * can never redirect the test database elsewhere.
     *
     * @return array<string, mixed>
     */
    public static function sqlite(string $path): array
    {
        if (is_link($path)) {
            throw new InvalidArgumentException(sprintf('Refusing the sqlite path [%s]: it is a symbolic link.', $path));
        }

        if (! file_exists($path)) {
            $handle = @fopen($path, 'x');
            if ($handle === false) {
                throw new InvalidArgumentException(sprintf('Cannot create the sqlite file [%s] exclusively.', $path));
            }
            fclose($handle);
            chmod($path, 0600);
        } elseif (! is_file($path)) {
            throw new InvalidArgumentException(sprintf('Refusing the sqlite path [%s]: it is not a regular file.', $path));
        }

        return [
            'driver' => 'sqlite',
            'database' => $path,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 10000,
            'journal_mode' => 'wal',
            'synchronous' => 'normal',
        ];
    }

    /**
     * A per-process temporary sqlite file inside a private (0700), unpredictably named directory created once per
     * process; a test that spawns a second process hands it this same path. Stable for a given $name within the
     * process, so a test can remove and recreate it.
     */
    public static function sqlitePath(string $name = 'integration'): string
    {
        if (self::$sqliteDirectory === null || ! is_dir(self::$sqliteDirectory)) {
            $directory = sys_get_temp_dir().'/firefly-it-'.bin2hex(random_bytes(8));
            if (! mkdir($directory, 0700) && ! is_dir($directory)) {
                throw new InvalidArgumentException(sprintf('Cannot create the private sqlite directory [%s].', $directory));
            }
            self::$sqliteDirectory = $directory;
        }

        return self::$sqliteDirectory.'/'.preg_replace('/[^A-Za-z0-9_-]/', '_', $name).'.sqlite';
    }

    /**
     * A standalone connection (no application): what a racer process or an app-less test uses.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function connect(string $lane, string $name = 'integration', array $overrides = []): Connection
    {
        $capsule = new Manager;
        $capsule->addConnection(self::connectionConfig($lane, $overrides), $name);

        return $capsule->getConnection($name);
    }

    public static function connectFor(string $driver, string $variable, string $name = 'integration'): Connection
    {
        $capsule = new Manager;
        $capsule->addConnection(self::connectionConfigFor($driver, $variable), $name);

        return $capsule->getConnection($name);
    }

    /**
     * @return array{host: string, port: int, user: string, password: string, vhost: string}
     */
    public static function rabbitmq(): array
    {
        $parts = self::parts(self::RABBITMQ) ?? throw new InvalidArgumentException(self::RABBITMQ.' is not set.');
        $vhost = $parts['vhost'] ?? '';

        return [
            'host' => $parts['host'],
            'port' => (int) ($parts['port'] ?? 5672),
            'user' => $parts['user'] ?? 'guest',
            'password' => $parts['password'] ?? 'guest',
            'vhost' => $vhost === '' ? '/' : $vhost,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function parseUrl(string $dsn): array
    {
        $url = parse_url($dsn);

        if (! is_array($url) || ($url['host'] ?? '') === '') {
            throw new InvalidArgumentException('Cannot read the DSN: a URL DSN needs a host (scheme://user:pass@host:port/db).');
        }

        $parts = ['scheme' => strtolower($url['scheme'] ?? ''), 'host' => $url['host']];

        if (isset($url['port'])) {
            $parts['port'] = (string) $url['port'];
        }
        if (isset($url['user'])) {
            $parts['user'] = rawurldecode($url['user']);
        }
        if (isset($url['pass'])) {
            $parts['password'] = rawurldecode($url['pass']);
        }

        $path = rawurldecode(ltrim($url['path'] ?? '', '/'));
        if ($path !== '') {
            $parts['dbname'] = $path;
            $parts['vhost'] = $path;
        }

        if (isset($url['query'])) {
            parse_str($url['query'], $query);
            foreach ($query as $key => $value) {
                $key = strtolower((string) $key);
                // The authority is authoritative: the query string only carries connection EXTRAS, never identity.
                if (is_string($value) && in_array($key, self::URL_QUERY_EXTRAS, true)) {
                    $parts[$key] = $value;
                }
            }
        }

        return $parts;
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Admin\Data;

use Firefly\Config\Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use PDO;
use Throwable;

/**
 * Tries a database connection that is not configured yet, and writes the config block for it.
 *
 * THE PROBLEM IT SOLVES is the twenty-minute loop everyone knows: edit `.env`, clear the config cache,
 * reload, read a driver error with no context, guess, repeat. The wizard collapses that to one form — the
 * connection is opened THERE, and what comes back is either the server's version string or the driver's own
 * message, which is the sentence that actually tells you whether it is the host, the credentials or the
 * database name.
 *
 * IT IS AN OUTBOUND-CONNECTION PRIMITIVE, AND IS GATED LIKE ONE. A form that opens a socket to a
 * caller-supplied host and port is a request-forgery tool by construction: the failure message
 * distinguishes "refused" from "timed out" from "authentication failed", which is enough to map a private
 * network. So it needs `firefly.admin.datasource.wizard` — off by default, on top of the dashboard's own
 * gate — and it is refused outright when `app.env` is production, which no key lifts. The same shape as the
 * feature-switch console, for the same reason: a convenience that is only ever wanted on a developer's
 * machine should be impossible to reach anywhere else.
 *
 * IT NEVER WRITES ANYTHING, and that took two attempts to be true. The result is a config snippet to paste,
 * not a file edit — persisting a connection would mean writing credentials from a browser form into a file
 * on disk, and the wizard's value (telling you whether the settings work) does not need that. But the sqlite
 * branch forwarded the caller's `database` straight to PDO, which CREATES the file it names: a field on this
 * form could drop an attacker-named file anywhere the worker could write. sqlite is now always tested
 * against `:memory:`, which is the only sqlite connection that has nothing to get wrong.
 */
final class ConnectionWizard
{
    private const array DRIVERS = ['mysql' => 3306, 'mariadb' => 3306, 'pgsql' => 5432, 'sqlsrv' => 1433, 'sqlite' => 0];

    public function __construct(
        private readonly ?ConnectionFactory $factory,
        private readonly bool $enabled,
        private readonly bool $production,
    ) {}

    public static function forContainer(Container $container, Config $config): self
    {
        $factory = null;
        try {
            $factory = $container->make(ConnectionFactory::class);
        } catch (Throwable) {
        }

        $environment = strtolower($config->string('app.env', 'production'));

        return new self(
            $factory,
            $config->bool('firefly.admin.datasource.wizard', false),
            in_array($environment, ['production', 'prod'], true),
        );
    }

    public function isAvailable(): bool
    {
        return $this->enabled && ! $this->production && $this->factory !== null;
    }

    public function isProduction(): bool
    {
        return $this->production;
    }

    /** @return list<string> */
    public function drivers(): array
    {
        return array_keys(self::DRIVERS);
    }

    public function defaultPort(string $driver): int
    {
        return self::DRIVERS[$driver] ?? 0;
    }

    /**
     * Open the connection described by $input and report what happened.
     *
     * @param  array<string, string>  $input
     * @return array{ok: bool, message: string, version: string, snippet: string}
     */
    public function test(array $input): array
    {
        if (! $this->isAvailable() || $this->factory === null) {
            return [
                'ok' => false,
                'message' => $this->production
                    ? 'Refused: the wizard is unavailable in production, and no configuration key changes that.'
                    : 'Refused: set firefly.admin.datasource.wizard to use it.',
                'version' => '',
                'snippet' => '',
            ];
        }

        $settings = $this->normalise($input);

        if (! in_array($settings['driver'], $this->drivers(), true)) {
            return ['ok' => false, 'message' => 'That is not a driver this wizard knows.', 'version' => '', 'snippet' => ''];
        }

        try {
            $connection = $this->factory->make($settings);

            // getPdo() FIRST, and that ordering is the whole difference between a useful failure and a
            // useless one. Going through selectOne() puts Laravel's reconnect wrapper in the way, which
            // catches the driver's exception and rethrows "Lost connection and no reconnector available" —
            // the same sentence for a wrong password, a closed port and a typo in the host. Forcing the
            // connection open directly lets the driver's own message through.
            $pdo = $connection->getPdo();
            $attribute = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            $version = is_scalar($attribute) ? (string) $attribute : '';

            // And a real statement after it, because a PDO handle proves the socket opened and the
            // credentials were accepted — not that the DATABASE named exists and is readable.
            $connection->selectOne('select 1');
            $connection->disconnect();

            return [
                'ok' => true,
                'message' => 'The connection opened and answered a query.',
                'version' => $version,
                'snippet' => $this->snippet($settings),
            ];
        } catch (Throwable $e) {
            // The driver's own message, verbatim, and the deepest one in the chain: "could not connect" is
            // the least useful thing to say here, and the whole point is that `password authentication
            // failed for user "app"` and `no such host` send you to different places.
            return ['ok' => false, 'message' => $this->deepest($e), 'version' => '', 'snippet' => ''];
        }
    }

    /**
     * The innermost message in an exception chain.
     *
     * Laravel wraps a connection failure at least once and sometimes twice, and every wrapper's message is
     * less specific than the one it wrapped. The driver sits at the bottom.
     */
    private function deepest(Throwable $e): string
    {
        while ($e->getPrevious() !== null) {
            $e = $e->getPrevious();
        }

        return $e->getMessage();
    }

    /**
     * @param  array<string, string>  $input
     * @return array<string, mixed>
     */
    private function normalise(array $input): array
    {
        $driver = strtolower(trim($input['driver'] ?? 'mysql'));
        $get = static fn (string $key, string $fallback = ''): string => trim($input[$key] ?? '') !== '' ? trim($input[$key]) : $fallback;

        if ($driver === 'sqlite') {
            // ONLY `:memory:`. Every other sqlite "database" is a PATH, and PDO CREATES it — so a form field
            // that reached the driver was a write primitive: `database=/var/www/html/x.php` (or a `file:`
            // URI with `?mode=rwc`) puts an attacker-named, attacker-located file on disk, which is a long
            // way from "test a connection" and flatly contradicts this class's promise to write nothing.
            // Testing a sqlite connection has no host and no credentials to get wrong, so there is nothing
            // the path would teach that :memory: does not.
            return ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true];
        }

        return [
            'driver' => $driver,
            'host' => $get('host', '127.0.0.1'),
            'port' => (int) $get('port', (string) $this->defaultPort($driver)),
            'database' => $get('database'),
            'username' => $get('username'),
            'password' => $input['password'] ?? '',
            'charset' => $get('charset', $driver === 'pgsql' ? 'utf8' : 'utf8mb4'),
            'prefix' => '',
            // A wizard that hung for the driver's default timeout — thirty seconds on some, none at all on
            // others — would look broken on exactly the wrong host.
            'options' => [PDO::ATTR_TIMEOUT => 5],
        ];
    }

    /**
     * The `config/database.php` block for settings that worked — with the password as an `env()` call, never
     * inlined. A wizard that printed a working credential into a file people paste into a repository would
     * be a very effective way of leaking one.
     *
     * @param  array<string, mixed>  $settings
     */
    private function snippet(array $settings): string
    {
        $string = static fn (string $key): string => is_scalar($settings[$key] ?? null) ? (string) $settings[$key] : '';

        if ($string('driver') === 'sqlite') {
            return "'sqlite' => [\n"
                ."    'driver' => 'sqlite',\n"
                ."    'database' => env('DB_DATABASE', database_path('database.sqlite')),\n"
                ."    'prefix' => '',\n"
                .'],';
        }

        return sprintf(
            "'%s' => [\n"
            ."    'driver' => '%s',\n"
            ."    'host' => env('DB_HOST', '%s'),\n"
            ."    'port' => env('DB_PORT', '%s'),\n"
            ."    'database' => env('DB_DATABASE', '%s'),\n"
            ."    'username' => env('DB_USERNAME', '%s'),\n"
            ."    'password' => env('DB_PASSWORD', ''),\n"
            ."    'charset' => '%s',\n"
            ."    'prefix' => '',\n"
            .'],',
            $string('driver'),
            $string('driver'),
            $string('host'),
            $string('port'),
            $string('database'),
            $string('username'),
            $string('charset'),
        );
    }
}

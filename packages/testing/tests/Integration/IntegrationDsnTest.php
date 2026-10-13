<?php

declare(strict_types=1);

use Firefly\Testing\Integration\IntegrationDsn;

/**
 * The shared DSN parser every integration test now goes through. The two forms are both in use: the program's
 * `host=…;port=…;dbname=…;user=…;password=…` and the URL form tools hand out (`amqp://guest:guest@host:5672/%2f`).
 * The ad-hoc parsers this replaces exploded a URL on `;` into ONE key, every lookup fell back to its default, and the
 * RabbitMQ round trip then failed against 127.0.0.1:5672 with an AMQPIOException that named neither the variable nor
 * the value — the baseline failure Task 2 closes.
 */
afterEach(function (): void {
    putenv('FIREFLY_TEST_DSN');
});

/** Removes a sqlite file and the WAL sidecars it leaves, only when present (an `@unlink` still reports a warning). */
function integrationDsnRemoveSqlite(string $path): void
{
    foreach ([$path, $path.'-wal', $path.'-shm'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}

it('parses the key=value form, trimming and lower-casing the keys', function () {
    expect(IntegrationDsn::parse(' host=127.0.0.1; Port=55432;dbname=firefly_w1;user=firefly;password=p=ss ;'))->toBe([
        'host' => '127.0.0.1',
        'port' => '55432',
        'dbname' => 'firefly_w1',
        'user' => 'firefly',
        'password' => 'p=ss',
    ]);
});

it('parses the URL form, decoding credentials, the path as database and vhost, and the query as extras', function () {
    expect(IntegrationDsn::parse('amqp://guest:g%40st@127.0.0.1:55672/%2f'))->toBe([
        'scheme' => 'amqp',
        'host' => '127.0.0.1',
        'port' => '55672',
        'user' => 'guest',
        'password' => 'g@st',
        'dbname' => '/',
        'vhost' => '/',
    ])->and(IntegrationDsn::parse('sqlsrv://sa:Secret-1@db:1433/firefly?encrypt=no&trust_server_certificate=true'))->toBe([
        'scheme' => 'sqlsrv',
        'host' => 'db',
        'port' => '1433',
        'user' => 'sa',
        'password' => 'Secret-1',
        'dbname' => 'firefly',
        'vhost' => 'firefly',
        'encrypt' => 'no',
        'trust_server_certificate' => 'true',
    ]);
});

it('refuses a DSN it cannot read instead of falling back to localhost defaults', function (string $dsn) {
    expect(fn () => IntegrationDsn::parse($dsn))->toThrow(InvalidArgumentException::class);
})->with([
    'no equals sign' => ['127.0.0.1:5432'],
    'no host' => ['port=5432;dbname=x'],
    'url without host' => ['pgsql:///firefly'],
]);

it('treats an unset or blank variable as missing', function () {
    putenv('FIREFLY_TEST_DSN=   ');
    expect(IntegrationDsn::value('FIREFLY_TEST_DSN'))->toBeNull()
        ->and(IntegrationDsn::missing('FIREFLY_TEST_DSN'))->toBeTrue()
        ->and(IntegrationDsn::parts('FIREFLY_TEST_DSN'))->toBeNull();

    putenv('FIREFLY_TEST_DSN=host=db;port=1');
    expect(IntegrationDsn::missing('FIREFLY_TEST_DSN'))->toBeFalse()
        ->and(IntegrationDsn::parts('FIREFLY_TEST_DSN'))->toBe(['host' => 'db', 'port' => '1']);
});

it('builds a Laravel connection config per driver', function () {
    putenv('FIREFLY_TEST_DSN=host=db;port=1433;dbname=firefly;user=sa;password=pw');

    expect(IntegrationDsn::connectionConfigFor('pgsql', 'FIREFLY_TEST_DSN'))->toBe([
        'driver' => 'pgsql', 'host' => 'db', 'port' => 1433, 'database' => 'firefly', 'username' => 'sa',
        'password' => 'pw', 'prefix' => '', 'charset' => 'utf8', 'search_path' => 'public',
    ])->and(IntegrationDsn::connectionConfigFor('mariadb', 'FIREFLY_TEST_DSN')['charset'])->toBe('utf8mb4')
        ->and(IntegrationDsn::connectionConfigFor('sqlsrv', 'FIREFLY_TEST_DSN'))->toMatchArray([
            'driver' => 'sqlsrv', 'encrypt' => 'yes', 'trust_server_certificate' => 'false', 'pooling' => false,
        ])->and(IntegrationDsn::connectionConfigFor('mysql', 'FIREFLY_TEST_DSN', ['database' => 'other'])['database'])->toBe('other');
});

it('defaults the port per driver when the DSN names none', function () {
    putenv('FIREFLY_TEST_DSN=host=db;dbname=f;user=u;password=p');

    expect(IntegrationDsn::connectionConfigFor('pgsql', 'FIREFLY_TEST_DSN')['port'])->toBe(5432)
        ->and(IntegrationDsn::connectionConfigFor('mysql', 'FIREFLY_TEST_DSN')['port'])->toBe(3306)
        ->and(IntegrationDsn::connectionConfigFor('sqlsrv', 'FIREFLY_TEST_DSN')['port'])->toBe(1433);
});

it('gives the sqlite lane a real file with WAL and a busy timeout, so two processes can share it', function () {
    $path = IntegrationDsn::sqlitePath('dsn-test');
    integrationDsnRemoveSqlite($path);

    $config = IntegrationDsn::sqlite($path);

    expect(is_file($path))->toBeTrue()
        ->and($config)->toMatchArray(['driver' => 'sqlite', 'database' => $path, 'journal_mode' => 'wal', 'busy_timeout' => 10000, 'foreign_key_constraints' => true])
        ->and(IntegrationDsn::skipReason('sqlite'))->toBeNull()
        ->and(IntegrationDsn::connect('sqlite', 'dsn-test', ['database' => $path])->selectOne('select 1 as one'))->toEqual((object) ['one' => 1]);

    integrationDsnRemoveSqlite($path);
});

it('names the variable to set when a server lane cannot run', function () {
    $reason = IntegrationDsn::skipReason('sqlsrv');

    expect($reason)->toBeString()
        ->and((string) $reason)->toMatch('/FIREFLY_SQLSRV_DSN|pdo_sqlsrv/')
        ->and(IntegrationDsn::lanes())->toBe(['pgsql', 'mysql', 'mariadb', 'sqlsrv', 'sqlite'])
        ->and(fn () => IntegrationDsn::skipReason('oracle'))->toThrow(InvalidArgumentException::class);
});

it('maps the RabbitMQ DSN in both forms to the five AMQP connection arguments', function () {
    $previous = getenv(IntegrationDsn::RABBITMQ);

    try {
        putenv(IntegrationDsn::RABBITMQ.'=host=127.0.0.1;port=55672;user=guest;password=pw');
        expect(IntegrationDsn::rabbitmq())->toBe(['host' => '127.0.0.1', 'port' => 55672, 'user' => 'guest', 'password' => 'pw', 'vhost' => '/']);

        putenv(IntegrationDsn::RABBITMQ.'=amqp://guest:pw@127.0.0.1:55672/%2f');
        expect(IntegrationDsn::rabbitmq())->toBe(['host' => '127.0.0.1', 'port' => 55672, 'user' => 'guest', 'password' => 'pw', 'vhost' => '/']);
    } finally {
        putenv($previous === false ? IntegrationDsn::RABBITMQ : IntegrationDsn::RABBITMQ.'='.$previous);
    }
});

it('never echoes the DSN, which carries the password, in a parse error', function (string $dsn) {
    $message = null;
    try {
        IntegrationDsn::parse($dsn);
    } catch (InvalidArgumentException $error) {
        $message = $error->getMessage();
    }

    expect($message)->toBeString()->not->toContain('S3cret-pw');
})->with([
    'segment without equals' => ['host=db;password=S3cret-pw;S3cret-pw'],
    'no host' => ['port=5432;password=S3cret-pw'],
    'url without host' => ['pgsql://u:S3cret-pw@/firefly'],
]);

it('validates the SQL Server certificate unless the DSN opts out explicitly', function () {
    putenv('FIREFLY_TEST_DSN=host=db;user=sa;password=pw');
    expect(IntegrationDsn::connectionConfigFor('sqlsrv', 'FIREFLY_TEST_DSN')['trust_server_certificate'])->toBe('false');

    putenv('FIREFLY_TEST_DSN=host=db;user=sa;password=pw;trust_server_certificate=true');
    expect(IntegrationDsn::connectionConfigFor('sqlsrv', 'FIREFLY_TEST_DSN')['trust_server_certificate'])->toBe('true');
});

it('lets a URL query string add extras but never override the authority', function () {
    expect(IntegrationDsn::parse('pgsql://u:pw@db:5432/firefly?host=evil&port=1&user=root&password=x&search_path=app'))->toBe([
        'scheme' => 'pgsql', 'host' => 'db', 'port' => '5432', 'user' => 'u', 'password' => 'pw',
        'dbname' => 'firefly', 'vhost' => 'firefly', 'search_path' => 'app',
    ]);
});

it('keeps sqlite files in a private directory and refuses a planted symlink', function () {
    $path = IntegrationDsn::sqlitePath('private-check');

    expect(IntegrationDsn::sqlitePath('private-check'))->toBe($path)
        ->and(dirname($path))->not->toBe(sys_get_temp_dir())
        ->and(fileperms(dirname($path)) & 0777)->toBe(0700);

    integrationDsnRemoveSqlite($path);
    symlink(__FILE__, $path);
    try {
        expect(fn () => IntegrationDsn::sqlite($path))->toThrow(InvalidArgumentException::class, 'symbolic link');
    } finally {
        unlink($path);
    }

    IntegrationDsn::sqlite($path);
    expect(fileperms($path) & 0777)->toBe(0600);
    integrationDsnRemoveSqlite($path);
});

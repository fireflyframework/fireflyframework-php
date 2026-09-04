<?php

declare(strict_types=1);

use Firefly\Admin\Data\ConnectionWizard;
use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Database\Connectors\ConnectionFactory;

/**
 * The connection wizard: a form that opens a socket to a host somebody typed.
 *
 * That is a request-forgery primitive by construction — the failure message distinguishes "refused" from
 * "timed out" from "authentication failed" well enough to map a private network — so most of what is worth
 * asserting here is what it REFUSES. It is off by default, on top of the dashboard's own gate, and refused
 * outright in production by a check no configuration key lifts.
 */
uses(FireflyDatabaseTestCase::class);

$wizard = static fn (bool $enabled = true, bool $production = false): ConnectionWizard => new ConnectionWizard(
    new ConnectionFactory(app()),
    $enabled,
    $production,
);

it('is unavailable until it is switched on, and refuses rather than silently doing nothing', function () use ($wizard) {
    $off = $wizard(enabled: false);

    expect($off->isAvailable())->toBeFalse()
        ->and($off->test(['driver' => 'sqlite', 'database' => ':memory:'])['ok'])->toBeFalse()
        ->and($off->test(['driver' => 'sqlite', 'database' => ':memory:'])['message'])->toContain('wizard');
});

it('is unavailable in production whatever the key says', function () use ($wizard) {
    $live = $wizard(enabled: true, production: true);

    expect($live->isAvailable())->toBeFalse()
        ->and($live->isProduction())->toBeTrue()
        ->and($live->test(['driver' => 'sqlite', 'database' => ':memory:'])['message'])->toContain('production');
});

it('opens a connection that works and hands back a config block', function () use ($wizard) {
    $result = $wizard()->test(['driver' => 'sqlite', 'database' => ':memory:']);

    expect($result['ok'])->toBeTrue()
        ->and($result['version'])->not->toBe('')
        ->and($result['snippet'])->toContain("'driver' => 'sqlite'");
});

it('never inlines a password into the config block it hands back', function () use ($wizard) {
    // A wizard that printed a working credential into a block people paste into a repository would be an
    // efficient way to leak one, so the snippet always spells the password as an env() call.
    $result = $wizard()->test(['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'x', 'username' => 'u', 'password' => 'hunter2-in-the-clear']);

    expect($result['snippet'])->not->toContain('hunter2-in-the-clear');

    // And on the success path, where a snippet is actually produced.
    $sqlite = $wizard()->test(['driver' => 'sqlite', 'database' => ':memory:']);
    expect($sqlite['snippet'])->not->toContain('hunter2-in-the-clear')
        ->toContain("env('DB_DATABASE'");
});

it('reports the driver\'s own message when a connection fails', function () use ($wizard) {
    // Going through selectOne() puts Laravel's reconnect wrapper in the way, which rethrows "Lost connection
    // and no reconnector available" for a wrong password, a closed port and a typo in the host alike. The
    // wizard forces the PDO open first and unwraps to the innermost exception, so the message that comes
    // back is the one that tells you where to look.
    // Port 1 is refused immediately by the loopback stack, so this is deterministic and fast — and it is a
    // network driver, which is the case sqlite (always :memory: now) cannot exercise.
    $result = $wizard()->test(['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '1', 'database' => 'x', 'username' => 'u', 'password' => 'p']);

    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->not->toContain('no reconnector')
        // Specific enough to act on: the driver names what it tried and why it failed, which is the whole
        // difference from the wrapper's one-size-fits-all sentence.
        ->and(strtolower($result['message']))->toContain('refused');
});

it('refuses a driver it does not know rather than handing it to a connector', function () use ($wizard) {
    expect($wizard()->test(['driver' => 'redis', 'host' => 'somewhere'])['message'])->toContain('not a driver');
});

it('never writes anything', function () {
    // The result is a snippet to paste, not a file edit. Persisting a connection would mean writing
    // credentials from a browser form into a file on disk, and telling you whether the settings work does
    // not require that.
    expect(get_class_methods(ConnectionWizard::class))
        ->not->toContain('save')
        ->not->toContain('persist')
        ->not->toContain('write');
});

it('cannot be used to create a file anywhere on disk', function () use ($wizard) {
    // sqlite's "database" is a PATH and PDO CREATES it, so forwarding the form field to the driver made this
    // a write primitive — `database=/tmp/planted.php`, or a `file:` URI with `?mode=rwc`, puts an
    // attacker-named file wherever the worker can write. That is a long way from "test a connection", and it
    // contradicted this class's own promise to write nothing.
    $planted = sys_get_temp_dir().'/firefly-wizard-planted-'.bin2hex(random_bytes(6)).'.php';
    $uri = sys_get_temp_dir().'/firefly-wizard-uri-'.bin2hex(random_bytes(6)).'.php';

    $wizard()->test(['driver' => 'sqlite', 'database' => $planted]);
    $wizard()->test(['driver' => 'sqlite', 'database' => 'file:'.$uri.'?mode=rwc']);

    expect(is_file($planted))->toBeFalse()
        ->and(is_file($uri))->toBeFalse();

    // And it still does the job: sqlite is tested against :memory:, which has no host, no credentials and
    // nothing a path would have taught.
    expect($wizard()->test(['driver' => 'sqlite', 'database' => $planted])['ok'])->toBeTrue();
});

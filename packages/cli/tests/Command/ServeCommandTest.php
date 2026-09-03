<?php

declare(strict_types=1);

use Firefly\Cli\Tests\Command\PassthroughCommandsTestCase;
use Firefly\Cli\Tests\Support\ArtisanAssertions;
use Firefly\Context\Scan\AppScan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Covers the report firefly:serve prints BEFORE it delegates — the URL, the runtime, and whether the app
 * booted compiled or scanned.
 *
 * Every case stubs the delegation target rather than starting a server. laravel/octane IS installed in this
 * monorepo, so ServeCommand's class_exists() probe always selects `octane:start` here (PassthroughCommandsTest
 * pins that fact); registering a same-named closure-free stub command makes the delegation return immediately
 * and keeps the suite off port 8000. The stub is registered through Artisan::registerCommand(), not
 * Artisan::command(), because the latter defers registration to a console-application `starting` callback
 * that never fires again once testbench has already built the console application.
 */
uses(PassthroughCommandsTestCase::class);

function stubOctaneStart(): void
{
    Artisan::registerCommand(new class extends Command
    {
        /** @var string */
        protected $signature = 'octane:start {--host=} {--port=}';

        /** @var string */
        protected $description = 'Test stub standing in for laravel/octane.';

        public function handle(): int
        {
            return self::SUCCESS;
        }
    });
}

/** A directory holding a compiled routes manifest, wired in through firefly.cache.path. */
function compiledCacheDir(): string
{
    $dir = sys_get_temp_dir().'/fserve-'.bin2hex(random_bytes(6));
    mkdir($dir, 0o755, true);
    file_put_contents($dir.'/'.AppScan::ROUTES, '<?php return [];');
    config()->set('firefly.cache.path', $dir);

    return $dir;
}

it('prints the URL it is about to serve', function () {
    /** @var PassthroughCommandsTestCase $this */
    stubOctaneStart();

    ArtisanAssertions::outputContains($this->artisan('firefly:serve'), 0, 'http://127.0.0.1:8000');
});

it('prints the URL for an explicit host and port', function () {
    /** @var PassthroughCommandsTestCase $this */
    stubOctaneStart();

    ArtisanAssertions::outputContains(
        $this->artisan('firefly:serve', ['--host' => '192.168.1.5', '--port' => '9001']),
        0,
        'http://192.168.1.5:9001',
    );
});

/**
 * 0.0.0.0 is a bind address, not an address a browser can open. The server still binds the wildcard —
 * only the printed link is rewritten — so the container/LAN use case keeps working while the link stays
 * clickable.
 */
it('prints a browsable link for the 0.0.0.0 wildcard bind', function () {
    /** @var PassthroughCommandsTestCase $this */
    stubOctaneStart();

    ArtisanAssertions::outputContains(
        $this->artisan('firefly:serve', ['--host' => '0.0.0.0']),
        0,
        'http://127.0.0.1:8000',
    );
});

it('reports a scanned boot when no compiled manifests exist', function () {
    /** @var PassthroughCommandsTestCase $this */
    config()->set('firefly.cache.path', sys_get_temp_dir().'/fserve-absent-'.bin2hex(random_bytes(6)));
    stubOctaneStart();

    ArtisanAssertions::outputContains($this->artisan('firefly:serve'), 0, 'scanned');
});

it('tells a scanned app how to compile itself', function () {
    /** @var PassthroughCommandsTestCase $this */
    config()->set('firefly.cache.path', sys_get_temp_dir().'/fserve-absent-'.bin2hex(random_bytes(6)));
    stubOctaneStart();

    ArtisanAssertions::outputContains($this->artisan('firefly:serve'), 0, 'firefly:cache');
});

it('reports a compiled boot when the routes manifest is on disk', function () {
    /** @var PassthroughCommandsTestCase $this */
    $dir = compiledCacheDir();
    stubOctaneStart();

    try {
        ArtisanAssertions::outputContains($this->artisan('firefly:serve'), 0, 'compiled');
    } finally {
        exec('rm -rf '.escapeshellarg($dir));
    }
});

it('names the artifact that put it in compiled mode', function () {
    /** @var PassthroughCommandsTestCase $this */
    $dir = compiledCacheDir();
    stubOctaneStart();

    try {
        ArtisanAssertions::outputContains($this->artisan('firefly:serve'), 0, AppScan::ROUTES);
    } finally {
        exec('rm -rf '.escapeshellarg($dir));
    }
});

it('still returns the delegated command exit code', function () {
    /** @var PassthroughCommandsTestCase $this */
    stubOctaneStart();

    ArtisanAssertions::exitCode($this->artisan('firefly:serve'), 0);
});

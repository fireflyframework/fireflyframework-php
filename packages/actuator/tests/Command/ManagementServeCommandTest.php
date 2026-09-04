<?php

declare(strict_types=1);

use Firefly\Actuator\Tests\Support\ArtisanAssertions;
use Firefly\Actuator\Tests\Support\ManagementServeCapstoneTestCase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Every passing case stubs the delegation target rather than starting a server — `artisan serve` blocks forever,
 * which in a test suite is indistinguishable from a hang. The stub goes in through Artisan::registerCommand(), not
 * Artisan::command(), because the latter defers registration to a console-application `starting` callback that
 * never fires again once testbench has already built the console application; this is the same technique
 * firefly/cli's ServeCommandTest uses on `octane:start`, for the same reason.
 */
uses(ManagementServeCapstoneTestCase::class);

/**
 * Replaces `serve` with a recorder and hands back the live log of what it was invoked with.
 *
 * @return ArrayObject<int, array{host: mixed, port: mixed}>
 */
function stubServe(): ArrayObject
{
    /** @var ArrayObject<int, array{host: mixed, port: mixed}> $calls */
    $calls = new ArrayObject;

    Artisan::registerCommand(new class($calls) extends Command
    {
        /** @var string */
        protected $signature = 'serve {--host=} {--port=}';

        /** @var string */
        protected $description = 'Test stub standing in for the framework\'s own serve command.';

        /** @param  ArrayObject<int, array{host: mixed, port: mixed}>  $calls */
        public function __construct(private readonly ArrayObject $calls)
        {
            parent::__construct();
        }

        public function handle(): int
        {
            $this->calls[] = ['host' => $this->option('host'), 'port' => $this->option('port')];

            return self::SUCCESS;
        }
    });

    return $calls;
}

it('fails, naming the config key, when no management port is configured', function () {
    /** @var ManagementServeCapstoneTestCase $this */
    ArtisanAssertions::outputContains(
        $this->artisan('firefly:management:serve'),
        Command::FAILURE,
        ['firefly.management.server.port'],
    );
});

it('refuses a management port that is the application port', function () {
    /** @var ManagementServeCapstoneTestCase $this */
    config()->set('firefly.server.port', 8000);

    ArtisanAssertions::outputContains(
        $this->artisan('firefly:management:serve', ['--port' => '8000']),
        Command::FAILURE,
        ['is the application port'],
    );
});

it('reports the actuator URL and delegates to serve on the management port', function () {
    /** @var ManagementServeCapstoneTestCase $this */
    $calls = stubServe();

    ArtisanAssertions::outputContains(
        $this->artisan('firefly:management:serve', ['--port' => '9001']),
        Command::SUCCESS,
        ['http://127.0.0.1:9001/actuator'],
    );

    expect($calls->getArrayCopy())->toBe([['host' => '127.0.0.1', 'port' => '9001']]);
});

// The bind argument is passed through EXACTLY as typed — only the printed link is rewritten, because pasting
// http://0.0.0.0:9001 into a browser is a coin flip across platforms.
it('binds the wildcard address as typed but prints a clickable one', function () {
    /** @var ManagementServeCapstoneTestCase $this */
    $calls = stubServe();

    ArtisanAssertions::outputContains(
        $this->artisan('firefly:management:serve', ['--port' => '9001', '--host' => '0.0.0.0']),
        Command::SUCCESS,
        ['http://127.0.0.1:9001/actuator', '0.0.0.0:9001'],
    );

    expect($calls->getArrayCopy())->toBe([['host' => '0.0.0.0', 'port' => '9001']]);
});

it('defaults the bind address to the configured management address', function () {
    /** @var ManagementServeCapstoneTestCase $this */
    config()->set('firefly.management.server.address', '10.0.0.4');
    config()->set('firefly.management.server.port', 9001);
    $calls = stubServe();

    ArtisanAssertions::outputContains(
        $this->artisan('firefly:management:serve'),
        Command::SUCCESS,
        ['10.0.0.4:9001'],
    );

    expect($calls->getArrayCopy())->toBe([['host' => '10.0.0.4', 'port' => '9001']]);
});

// The prefix belongs in the printed URL: an operator following a link to /actuator on a deployment whose actuator
// lives at /manage/actuator has been sent to a 404 by the very command meant to make this work locally.
it('prints the management server base path in the URL', function () {
    /** @var ManagementServeCapstoneTestCase $this */
    config()->set('firefly.management.server.base-path', '/manage');
    stubServe();

    ArtisanAssertions::outputContains(
        $this->artisan('firefly:management:serve', ['--port' => '9001']),
        Command::SUCCESS,
        ['http://127.0.0.1:9001/manage/actuator'],
    );
});

// A malformed --port must not quietly fall back to the configured port: the operator typed a port because they
// meant that port, and a listener bound somewhere else is the kind of "it ran, so it worked" outcome that gets
// noticed only when the health check they were debugging still fails.
it('rejects a malformed --port instead of falling back to the configured one', function (string $typed) {
    /** @var ManagementServeCapstoneTestCase $this */
    config()->set('firefly.management.server.port', 9001);
    $calls = stubServe();

    ArtisanAssertions::outputContains(
        $this->artisan('firefly:management:serve', ['--port' => $typed]),
        Command::FAILURE,
        ['is not a TCP port between 1 and 65535'],
    );

    expect($calls->getArrayCopy())->toBe([]);
})->with([['abc'], ['0'], ['70000']]);

<?php

declare(strict_types=1);

use Firefly\Callbacks\Execution\CallbackStatus;
use Firefly\Callbacks\Execution\ExecutionLog;
use Firefly\Callbacks\Tests\Support\CallbacksArtisan;
use Firefly\Callbacks\Tests\Support\CallbacksCapstoneTestCase;
use Illuminate\Support\Facades\Http;

uses(CallbacksCapstoneTestCase::class);

it('lists the subscriptions', function () {
    /** @var CallbacksCapstoneTestCase $this */
    CallbacksArtisan::pending($this->artisan('firefly:callbacks:list'))
        ->expectsTable(['ID', 'Name', 'URL', 'Event types', 'Active', 'Signed'], [
            ['sub-orders', 'partner orders', 'https://partner.example.com/hooks/orders', 'order.created', 'yes', 'yes'],
        ])
        ->assertExitCode(0);
});

it('replays the failed deliveries and lists them as delivered afterwards', function () {
    /** @var CallbacksCapstoneTestCase $this */
    Http::fake(['partner.example.com/*' => Http::sequence()->push('', 503)->push('', 503)->push('', 503)->push('', 204)]);
    $this->events->publish('orders', 'order.created', ['id' => 9]);
    $failed = $this->app()->make(ExecutionLog::class)->recent(1, CallbackStatus::Failed)[0];

    CallbacksArtisan::pending($this->artisan('firefly:callbacks:list', ['--executions' => true, '--status' => 'failed']))
        ->expectsOutputToContain($failed->id)
        ->assertExitCode(0);

    CallbacksArtisan::pending($this->artisan('firefly:callbacks:replay', ['--failed' => true]))
        ->expectsOutputToContain("{$failed->id}: delivered after 4 attempt(s)")
        ->assertExitCode(0);

    expect($this->app()->make(ExecutionLog::class)->find($failed->id)?->status)->toBe(CallbackStatus::Delivered);
    Http::assertSentCount(4);
});

it('replays named executions, reporting the ones it cannot find', function () {
    /** @var CallbacksCapstoneTestCase $this */
    CallbacksArtisan::pending($this->artisan('firefly:callbacks:replay', ['ids' => ['no-such-execution']]))
        ->expectsOutputToContain('No execution no-such-execution')
        ->expectsOutputToContain('Nothing to replay')
        ->assertExitCode(0);
});

it('refuses a replay with neither ids nor --failed, and an unknown status filter', function () {
    /** @var CallbacksCapstoneTestCase $this */
    CallbacksArtisan::pending($this->artisan('firefly:callbacks:replay'))->expectsOutputToContain('Name execution ids, or pass --failed')->assertExitCode(1);
    CallbacksArtisan::pending($this->artisan('firefly:callbacks:list', ['--executions' => true, '--status' => 'bogus']))->expectsOutputToContain('--status must be pending, delivered or failed')->assertExitCode(1);
});

it('exits 1 when a replayed delivery fails again', function () {
    /** @var CallbacksCapstoneTestCase $this */
    Http::fake(['partner.example.com/*' => Http::response('', 500)]);
    $this->events->publish('orders', 'order.created', ['id' => 10]);

    // Three attempts at publish, then the replay's: the fifth failure opens the endpoint's breaker (threshold 5),
    // so the replay ends on "circuit open" without a sixth request reaching the partner.
    CallbacksArtisan::pending($this->artisan('firefly:callbacks:replay', ['--failed' => true]))
        ->expectsOutputToContain('failed after 5 attempt(s) — circuit open for https://partner.example.com:443')
        ->assertExitCode(1);
    Http::assertSentCount(5);
});

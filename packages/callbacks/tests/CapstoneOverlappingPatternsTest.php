<?php

declare(strict_types=1);

use Firefly\Callbacks\Execution\CallbackStatus;
use Firefly\Callbacks\Execution\ExecutionLog;
use Firefly\Callbacks\Tests\Support\OverlappingPatternsCapstoneTestCase;
use Illuminate\Support\Facades\Http;

uses(OverlappingPatternsCapstoneTestCase::class);

it('delivers an event matched by several overlapping patterns exactly once', function () {
    /** @var OverlappingPatternsCapstoneTestCase $this */
    Http::fake(['partner.example.com/*' => Http::response('', 204)]);

    $this->events->publish('orders', 'order.created', ['id' => 7]);

    Http::assertSentCount(1);
    expect($this->app()->make(ExecutionLog::class)->recent())->toHaveCount(1);
});

it('runs one retry cycle, not one per matching pattern, against a failing endpoint', function () {
    /** @var OverlappingPatternsCapstoneTestCase $this */
    Http::fake(['partner.example.com/*' => Http::response('', 503)]);

    $this->events->publish('orders', 'order.created', ['id' => 8]);

    Http::assertSentCount(3);
    $failed = $this->app()->make(ExecutionLog::class)->recent(10, CallbackStatus::Failed);
    expect($failed)->toHaveCount(1)->and($failed[0]->attempts)->toBe(3);
});

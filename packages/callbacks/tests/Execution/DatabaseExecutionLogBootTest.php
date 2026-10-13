<?php

declare(strict_types=1);

use Firefly\Callbacks\Execution\CallbackExecution;
use Firefly\Callbacks\Execution\CallbackStatus;
use Firefly\Callbacks\Execution\DatabaseExecutionLog;
use Firefly\Callbacks\Execution\ExecutionLog;
use Firefly\Callbacks\Schema\CallbackSchema;
use Firefly\Callbacks\Subscription\CallbackSubscription;
use Firefly\Callbacks\Subscription\InMemorySubscriptionRegistry;
use Firefly\Callbacks\Subscription\SubscriptionRegistry;
use Firefly\Callbacks\Tests\Support\CallbacksDatabaseLogTestCase;
use Firefly\Eda\EventEnvelope;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(CallbacksDatabaseLogTestCase::class);

it('binds the database execution log on log.store=database and writes through the migrated table', function () {
    /** @var CallbacksDatabaseLogTestCase $this */
    expect(Artisan::call('migrate'))->toBe(0);

    $log = $this->app()->make(ExecutionLog::class);
    $subscription = new CallbackSubscription('sub-1', 'orders', 'https://partner.example.com/hooks', ['order.*']);
    $execution = CallbackExecution::pending($subscription, new EventEnvelope('order.created', 'orders', ['id' => 1], [], 'e-1'), 10);
    $log->record($execution);
    $log->record($execution->withOutcome(CallbackStatus::Delivered, 1, 200, null, 11));

    expect($log)->toBeInstanceOf(DatabaseExecutionLog::class)
        ->and($this->app()->make(SubscriptionRegistry::class))->toBeInstanceOf(InMemorySubscriptionRegistry::class)
        ->and(DB::table(CallbackSchema::EXECUTIONS)->where('id', $execution->id)->value('status'))->toBe('delivered')
        ->and($log->find($execution->id)?->createdAt)->toBe(10);
});

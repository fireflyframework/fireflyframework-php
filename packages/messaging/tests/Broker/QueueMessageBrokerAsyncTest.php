<?php

declare(strict_types=1);

use Firefly\Messaging\Broker\DispatchMessageJob;
use Firefly\Messaging\Broker\QueueMessageBroker;
use Firefly\Messaging\Tests\Support\QueueMessageBrokerTestCase;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;

uses(QueueMessageBrokerTestCase::class);

it('enqueues ONE DispatchMessageJob and does NOT invoke the subscriber synchronously', function () {
    Bus::fake();

    /** @var QueueMessageBrokerTestCase $this */
    $broker = new QueueMessageBroker($this->brokerApp(), null, null);
    $ran = false;
    $broker->subscribe('orders', function () use (&$ran): void {
        $ran = true;
    });

    $broker->publish('orders', 'raw-bytes', 'k9', ['h' => 'v']);

    // publish() returned before any subscriber ran — delivery is deferred to the worker.
    expect($ran)->toBeFalse();

    Bus::assertDispatched(DispatchMessageJob::class, function (DispatchMessageJob $job): bool {
        return $job->topic === 'orders' && $job->value === 'raw-bytes' && $job->key === 'k9' && $job->headers === ['h' => 'v'];
    });
});

/**
 * A minimal spy standing in for whatever Dispatcher gets bound AFTER QueueMessageBroker is constructed —
 * deliberately NOT Bus::fake(): Illuminate\Queue\CallQueuedHandler (which actually RUNS a queued job) resolves its
 * OWN Dispatcher fresh from the container at process time and calls dispatchNow() on it, so BusFake's short-circuit
 * would still catch — and silently mask — a job pushed onto a REAL queue by a stale constructor-cached Dispatcher.
 * Worse: in the test above, Bus::fake() is called BEFORE the broker is constructed, so even a
 * constructor-caching bug would cache the ALREADY-faked Dispatcher and still pass assertDispatched — that test
 * alone does NOT discriminate the mutation. This spy proves the OUTER container resolution inside publish() itself
 * is fresh, independent of both of those maskings, by binding strictly AFTER construction.
 */
final class RecordingMessageDispatcherSpy implements Dispatcher
{
    /** @var list<mixed> */
    public array $dispatched = [];

    public function dispatch($command): mixed
    {
        $this->dispatched[] = $command;

        return null;
    }

    public function dispatchSync($command, $handler = null): mixed
    {
        return null;
    }

    public function dispatchNow($command, $handler = null): mixed
    {
        return null;
    }

    public function dispatchAfterResponse($command, $handler = null): void {}

    /**
     * @param  mixed  $jobs
     */
    public function chain($jobs = null): mixed
    {
        return null;
    }

    public function hasCommandHandler($command): bool
    {
        return false;
    }

    public function getCommandHandler($command): mixed
    {
        return null;
    }

    /**
     * @param  array<mixed>  $pipes
     */
    public function pipeThrough(array $pipes): static
    {
        return $this;
    }

    /**
     * @param  array<mixed>  $map
     */
    public function map(array $map): static
    {
        return $this;
    }
}

it('resolves the Dispatcher FRESH on every publish() call — a Dispatcher bound AFTER construction is still '
    .'used, never a stale constructor-time reference', function () {
        /** @var QueueMessageBrokerTestCase $this */
        $app = $this->brokerApp();

        // Constructed while the container still holds whatever Dispatcher::class binding existed at boot — mirrors
        // the real timing: the auto-config binds QueueMessageBroker as a container SINGLETON once, at boot, long
        // before anything later rebinds the Dispatcher (a test's fake, or the app swapping it for any other reason).
        $broker = new QueueMessageBroker($app, null, null);

        $spy = new RecordingMessageDispatcherSpy;
        $app->instance(Dispatcher::class, $spy);

        $broker->publish('orders', 'raw-bytes-2', 'k7', ['trace' => 'xyz']);

        expect($spy->dispatched)->toHaveCount(1);
        /** @var DispatchMessageJob $dispatchedJob */
        $dispatchedJob = $spy->dispatched[0];
        expect($dispatchedJob)->toBeInstanceOf(DispatchMessageJob::class)
            ->and($dispatchedJob->topic)->toBe('orders')
            ->and($dispatchedJob->value)->toBe('raw-bytes-2')
            ->and($dispatchedJob->key)->toBe('k7')
            ->and($dispatchedJob->headers)->toBe(['trace' => 'xyz']);
    });

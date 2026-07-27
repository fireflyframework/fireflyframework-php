<?php

declare(strict_types=1);

use Firefly\Eda\Bus\DispatchEventJob;
use Firefly\Eda\Bus\QueueEventBus;
use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\Tests\Support\QueueEventBusTestCase;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;

uses(QueueEventBusTestCase::class);

it('enqueues ONE DispatchEventJob carrying the envelope and does NOT invoke the handler synchronously', function () {
    Bus::fake();

    /** @var QueueEventBusTestCase $this */
    $bus = new QueueEventBus(new SubscriberRegistry, $this->app(), null, null);
    $ran = false;
    $bus->subscribe('order.*', function () use (&$ran): void {
        $ran = true;
    });

    $bus->publish('firefly.events', 'order.placed', ['id' => 1]);

    // publish() returned before any handler ran — delivery is deferred to the worker.
    expect($ran)->toBeFalse();

    Bus::assertDispatched(DispatchEventJob::class, function (DispatchEventJob $job): bool {
        // $job->envelope is a non-nullable EventEnvelope by declaration (no instanceof needed/allowed at
        // PHPStan level max: it would always be true), so only the substantive fields are asserted here.
        return $job->envelope->eventType === 'order.placed'
            && $job->envelope->payload === ['id' => 1];
    });
});

/**
 * A minimal spy standing in for whatever Dispatcher gets bound AFTER QueueEventBus is constructed — deliberately
 * NOT Bus::fake(): Illuminate\Queue\CallQueuedHandler (which actually RUNS a queued job) resolves its OWN
 * Dispatcher fresh from the container at process time and calls dispatchNow() on it, so BusFake's short-circuit
 * would still catch — and silently mask — a job pushed onto a REAL queue by a stale constructor-cached Dispatcher.
 * This spy proves the OUTER container resolution inside publish() itself is fresh, independent of that inner
 * re-resolution.
 */
final class RecordingDispatcherSpy implements Dispatcher
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
        /** @var QueueEventBusTestCase $this */
        $app = $this->app();

        // Constructed while the container still holds whatever Dispatcher::class binding existed at boot — mirrors
        // the real timing: the auto-config binds QueueEventBus as a container SINGLETON once, at boot, long before
        // anything later rebinds the Dispatcher (a test's fake, or the app swapping it for any other reason).
        $bus = new QueueEventBus(new SubscriberRegistry, $app, null, null);

        $spy = new RecordingDispatcherSpy;
        $app->instance(Dispatcher::class, $spy);

        $bus->publish('firefly.events', 'order.shipped', ['id' => 4]);

        expect($spy->dispatched)->toHaveCount(1);
        /** @var DispatchEventJob $dispatchedJob */
        $dispatchedJob = $spy->dispatched[0];
        expect($dispatchedJob)->toBeInstanceOf(DispatchEventJob::class)
            ->and($dispatchedJob->envelope->eventType)->toBe('order.shipped');
    });

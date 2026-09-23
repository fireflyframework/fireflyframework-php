<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Scheduling\Schedule\InitialDelayGate;
use Firefly\Scheduling\Schedule\ScheduledDescriptor;
use Firefly\Scheduling\Tests\Support\RecordingLogger;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as ConfigRepository;
use Psr\Log\LoggerInterface;

/** Ten minutes, already parsed — the gate is handed seconds, never a duration string (see isDue()). */
const TEN_MINUTES = 600.0;

function delayGate(?Repository $cache = null, bool $enabled = true, ?LoggerInterface $logger = null): InitialDelayGate
{
    return new InitialDelayGate(
        $cache ?? new Repository(new ArrayStore),
        new Config(new ConfigRepository(['firefly' => ['scheduling' => ['initial-delay' => ['enabled' => $enabled]]]])),
        $logger,
    );
}

function delayedTask(string $delay): ScheduledDescriptor
{
    return new ScheduledDescriptor('App\\Tasks\\Warmup', 'run', null, '1m', null, $delay);
}

it('refuses the first tick and every tick inside the delay window', function (): void {
    $cache = new Repository(new ArrayStore);
    $gate = delayGate($cache);
    $task = delayedTask('10m');

    expect($gate->isDue($task, TEN_MINUTES, 1_000.0))->toBeFalse()
        ->and($gate->isDue($task, TEN_MINUTES, 1_000.0 + 599.0))->toBeFalse();
});

it('admits every tick once the window has passed', function (): void {
    $cache = new Repository(new ArrayStore);
    $gate = delayGate($cache);
    $task = delayedTask('10m');

    $gate->isDue($task, TEN_MINUTES, 1_000.0);

    expect($gate->isDue($task, TEN_MINUTES, 1_000.0 + 600.0))->toBeTrue()
        ->and($gate->isDue($task, TEN_MINUTES, 1_000.0 + 10_000.0))->toBeTrue();
});

it('anchors on the FIRST observation, so a cron-driven schedule:run does not restart the window', function (): void {
    $cache = new Repository(new ArrayStore);
    $task = delayedTask('10m');

    // Each call is a fresh gate — a fresh `schedule:run` process — sharing only the cache.
    delayGate($cache)->isDue($task, TEN_MINUTES, 1_000.0);
    expect(delayGate($cache)->isDue($task, TEN_MINUTES, 1_000.0 + 300.0))->toBeFalse();
    expect(delayGate($cache)->isDue($task, TEN_MINUTES, 1_000.0 + 601.0))->toBeTrue();
});

it('admits a descriptor with no initial delay unconditionally', function (): void {
    expect(delayGate()->isDue(new ScheduledDescriptor('App\\Tasks\\Warmup', 'run', null, '1m'), TEN_MINUTES, 1_000.0))->toBeTrue();
});

it('keys the anchor per task, so two tasks do not share a window', function (): void {
    $cache = new Repository(new ArrayStore);
    $gate = delayGate($cache);

    $gate->isDue(delayedTask('10m'), TEN_MINUTES, 1_000.0);
    $other = new ScheduledDescriptor('App\\Tasks\\Other', 'run', null, '1m', null, '1s');

    expect($gate->isDue($other, 1.0, 1_000.0))->toBeFalse()
        ->and($gate->isDue($other, 1.0, 1_002.0))->toBeTrue();
});

it('admits every tick, and writes no anchor at all, while the gate is switched off', function (): void {
    // The refusal for a switched-off gate lives at BOOT (ScheduleWiringPass); the gate itself must then be
    // inert rather than half-applied, so a manifest that somehow reaches it is not silently held back.
    $cache = new Repository(new ArrayStore);

    expect(delayGate($cache, enabled: false)->isDue(delayedTask('10m'), TEN_MINUTES, 1_000.0))->toBeTrue()
        ->and($cache->get(InitialDelayGate::ANCHOR_PREFIX.'App\\Tasks\\Warmup::run'))->toBeNull();
});

/*
 * THE UNREADABLE ANCHOR, WHICH USED TO FAIL CLOSED AND SAY NOTHING. `NullStore::get()` returns null
 * unconditionally and its `forever()` returns false, so `add()` stored nothing, `get()` came back null, the
 * anchor fell back to `$now` and `$now >= $now + 600.0` was false on EVERY tick, forever — the delayed task
 * never ran, nothing was logged, nothing was thrown. That is precisely the silent hang the class docblock
 * says the anchor exists to prevent, and it is also what a Redis outage or a missing `cache` table looked
 * like. A store that cannot read back its own write cannot gate anything, so the gate now admits the tick
 * and says why.
 */

it('ADMITS every tick when the anchor cannot be read back, instead of hanging the task forever', function (): void {
    $gate = delayGate(new Repository(new NullStore));
    $task = delayedTask('10m');

    $verdicts = [];
    for ($tick = 0; $tick < 10; $tick++) {
        $verdicts[] = $gate->isDue($task, TEN_MINUTES, 1_000.0 + $tick * 600.0);
    }

    expect($verdicts)->toBe(array_fill(0, 10, true));
});

it('reports the non-persisting anchor ONCE per task, not once a tick', function (): void {
    $logger = new RecordingLogger;
    $gate = delayGate(new Repository(new NullStore), logger: $logger);
    $task = delayedTask('10m');

    for ($tick = 0; $tick < 10; $tick++) {
        $gate->isDue($task, TEN_MINUTES, 1_000.0 + $tick * 600.0);
    }

    expect($logger->mentioning('is not persisting'))->toHaveCount(1)
        ->and($logger->records[0]['level'])->toBe('warning')
        ->and($logger->records[0]['message'])->toContain('App\\Tasks\\Warmup::run')
        ->and($logger->records[0]['message'])->toContain(InitialDelayGate::STORE_KEY);
});

it('admits the tick, and does not rethrow, when the cache store itself fails', function (): void {
    // A filter that throws aborts the ENTIRE schedule:run for that minute (ScheduleRunCommand calls
    // filtersPass() outside the try/catch that wraps run()), so a cache blip must cost this one task its
    // gate and nothing else.
    $logger = new RecordingLogger;
    $exploding = new Repository(new class extends NullStore
    {
        /**
         * @param  string  $key
         * @return never
         */
        public function get($key)
        {
            throw new RuntimeException('Connection refused [tcp://127.0.0.1:6379]');
        }
    });

    expect(delayGate($exploding, logger: $logger)->isDue(delayedTask('10m'), TEN_MINUTES, 1_000.0))->toBeTrue()
        ->and($logger->mentioning('could not be reached'))->toHaveCount(1);
});

it('never becomes due when each tick runs in its own process against a store that does not outlive it', function (): void {
    // THE DEGRADATION, PINNED AS IT REALLY IS. A fresh ArrayStore per gate is the cron `schedule:run` process
    // model, and there the write and the read both SUCCEED — the gate has nothing to detect, it simply
    // re-anchors at its own $now every minute and the window never elapses. This is why the store shape is
    // warned about one level up, as the Schedule is built (ScheduleWiringPass::warnOnVolatileAnchorStore),
    // and why the config reference says an initial delay needs a store shared across processes under cron.
    $task = delayedTask('10m');

    $verdicts = [];
    for ($tick = 0; $tick < 10; $tick++) {
        $verdicts[] = delayGate(new Repository(new ArrayStore))->isDue($task, TEN_MINUTES, 1_000.0 + $tick * 600.0);
    }

    expect($verdicts)->toBe(array_fill(0, 10, false));
});

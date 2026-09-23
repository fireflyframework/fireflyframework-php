<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Scheduling\Schedule\InitialDelayGate;
use Firefly\Scheduling\Schedule\ScheduledDescriptor;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as ConfigRepository;

function delayGate(?Repository $cache = null, bool $enabled = true): InitialDelayGate
{
    return new InitialDelayGate(
        $cache ?? new Repository(new ArrayStore),
        new Config(new ConfigRepository(['firefly' => ['scheduling' => ['initial-delay' => ['enabled' => $enabled]]]])),
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

    expect($gate->isDue($task, 1_000.0))->toBeFalse()
        ->and($gate->isDue($task, 1_000.0 + 599.0))->toBeFalse();
});

it('admits every tick once the window has passed', function (): void {
    $cache = new Repository(new ArrayStore);
    $gate = delayGate($cache);
    $task = delayedTask('10m');

    $gate->isDue($task, 1_000.0);

    expect($gate->isDue($task, 1_000.0 + 600.0))->toBeTrue()
        ->and($gate->isDue($task, 1_000.0 + 10_000.0))->toBeTrue();
});

it('anchors on the FIRST observation, so a cron-driven schedule:run does not restart the window', function (): void {
    $cache = new Repository(new ArrayStore);
    $task = delayedTask('10m');

    // Each call is a fresh gate — a fresh `schedule:run` process — sharing only the cache.
    delayGate($cache)->isDue($task, 1_000.0);
    expect(delayGate($cache)->isDue($task, 1_000.0 + 300.0))->toBeFalse();
    expect(delayGate($cache)->isDue($task, 1_000.0 + 601.0))->toBeTrue();
});

it('admits a descriptor with no initial delay unconditionally', function (): void {
    expect(delayGate()->isDue(new ScheduledDescriptor('App\\Tasks\\Warmup', 'run', null, '1m'), 1_000.0))->toBeTrue();
});

it('keys the anchor per task, so two tasks do not share a window', function (): void {
    $cache = new Repository(new ArrayStore);
    $gate = delayGate($cache);

    $gate->isDue(delayedTask('10m'), 1_000.0);
    $other = new ScheduledDescriptor('App\\Tasks\\Other', 'run', null, '1m', null, '1s');

    expect($gate->isDue($other, 1_000.0))->toBeFalse()
        ->and($gate->isDue($other, 1_002.0))->toBeTrue();
});

it('admits every tick, and writes no anchor at all, while the gate is switched off', function (): void {
    // The refusal for a switched-off gate lives at BOOT (ScheduleWiringPass); the gate itself must then be
    // inert rather than half-applied, so a manifest that somehow reaches it is not silently held back.
    $cache = new Repository(new ArrayStore);

    expect(delayGate($cache, enabled: false)->isDue(delayedTask('10m'), 1_000.0))->toBeTrue()
        ->and($cache->get(InitialDelayGate::ANCHOR_PREFIX.'App\\Tasks\\Warmup::run'))->toBeNull();
});

<?php

declare(strict_types=1);

use Firefly\Cli\Tests\Command\PassthroughCommandsTestCase;
use Firefly\Cli\Tests\Support\ArtisanAssertions;
use Firefly\Scheduling\Schedule\ScheduledDescriptor;
use Firefly\Scheduling\Schedule\ScheduledManifest;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

/**
 * THE CLOCK NOBODY STARTED. A #[Scheduled] method fires only under `schedule:work` (or a cron-driven
 * `schedule:run`), and nothing in a fresh LaraFly application starts either: `firefly:serve` serves and a
 * developer who has written five `#[Scheduled(fixedRate: '60s')]` methods tests a product whose clock is
 * stopped — a run that never leaves its first step, a schedule that reports a next-run time and never fires —
 * and reads the engine as broken. One real application lost a whole verification pass to exactly that.
 *
 * `firefly:schedule` is the companion to `firefly:serve`: it prints every scheduled task the manifest holds,
 * with its cadence, and then delegates to `schedule:work` (or `schedule:run` with --once). The listing is the
 * point as much as the delegation: a task that is missing from it was never compiled, and that is visible on
 * line one instead of being diagnosed as "the scheduler is broken".
 *
 * Every case stubs the delegation target rather than running a real scheduler (a long-running process).
 */
uses(PassthroughCommandsTestCase::class);

function stubScheduleWorker(string $name): void
{
    Artisan::registerCommand(new class($name) extends Command
    {
        public function __construct(string $name)
        {
            $this->signature = $name.' {--run-output-file=} {--max-runtime=}';
            $this->description = 'Test stub standing in for the Laravel scheduler.';
            parent::__construct();
        }

        public function handle(): int
        {
            return self::SUCCESS;
        }
    });
}

/**
 * @param  list<ScheduledDescriptor>  $descriptors
 */
function bindScheduledManifest(PassthroughCommandsTestCase $test, array $descriptors): void
{
    $test->app()->instance(ScheduledManifest::class, new ScheduledManifest($descriptors));
}

/**
 * The whole listing in one run. Kernel::call() + output() rather than several PendingCommand runs, because a
 * PendingCommand's expectsOutputToContain() is one expectation per run and this test reads five things off
 * one screen.
 *
 * @param  array<string, mixed>  $parameters
 * @return array{0: int, 1: string}
 */
function runSchedule(PassthroughCommandsTestCase $test, array $parameters = []): array
{
    /** @var Kernel $kernel */
    $kernel = $test->app()->make(Kernel::class);
    $exit = $kernel->call('firefly:schedule', $parameters);

    return [$exit, $kernel->output()];
}

it('lists every scheduled task with its cadence before delegating to schedule:work', function () {
    /** @var PassthroughCommandsTestCase $this */
    stubScheduleWorker('schedule:work');
    bindScheduledManifest($this, [
        new ScheduledDescriptor(class: 'App\\Runs\\RunSweeper', method: 'tick', fixedRate: '10s', lockName: 'App\\Runs\\RunSweeper::tick'),
        new ScheduledDescriptor(class: 'App\\Billing\\Invoicer', method: 'nightly', cron: '0 3 * * *', zone: 'Europe/Madrid'),
    ]);

    [$exit, $output] = runSchedule($this);

    expect($exit)->toBe(0)
        ->and($output)->toContain('2 scheduled')
        ->toContain('App\\Runs\\RunSweeper::tick — every 10 seconds')
        ->toContain('locked: App\\Runs\\RunSweeper::tick')
        ->toContain('App\\Billing\\Invoicer::nightly — 0 3 * * *')
        ->toContain('Europe/Madrid')
        ->toContain('schedule:work');
});

it('says so, loudly, when the manifest holds no scheduled task at all', function () {
    /** @var PassthroughCommandsTestCase $this */
    stubScheduleWorker('schedule:work');
    bindScheduledManifest($this, []);

    ArtisanAssertions::outputContains($this->artisan('firefly:schedule'), 0, 'No #[Scheduled] task');
});

it('delegates to schedule:run once with --once, for a cron entry or a health probe', function () {
    /** @var PassthroughCommandsTestCase $this */
    stubScheduleWorker('schedule:run');
    bindScheduledManifest($this, [new ScheduledDescriptor(class: 'App\\Runs\\RunSweeper', method: 'tick', fixedRate: '60s')]);

    ArtisanAssertions::outputContains($this->artisan('firefly:schedule', ['--once' => true]), 0, 'schedule:run');
});

it('names the cadence a sub-minute rate is actually rounded to', function () {
    /** @var PassthroughCommandsTestCase $this */
    stubScheduleWorker('schedule:work');
    bindScheduledManifest($this, [new ScheduledDescriptor(class: 'App\\X', method: 'tick', fixedRate: '7s')]);

    // 7 s is not a divisor of sixty; ScheduleWiringPass rounds it UP to 10 s, and the listing must say what
    // will really happen rather than echo the attribute.
    ArtisanAssertions::outputContains($this->artisan('firefly:schedule'), 0, 'every 10 seconds');
});

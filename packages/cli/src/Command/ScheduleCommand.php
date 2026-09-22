<?php

declare(strict_types=1);

namespace Firefly\Cli\Command;

use Firefly\Scheduling\Schedule\Cadence;
use Firefly\Scheduling\Schedule\ScheduledDescriptor;
use Firefly\Scheduling\Schedule\ScheduledManifest;
use Illuminate\Console\Command;

/**
 * The companion to `firefly:serve`: prints every #[Scheduled] task the manifest holds, with the cadence it
 * will REALLY run at, and delegates to Laravel's `schedule:work` (or a single `schedule:run` with --once).
 *
 * THE CLOCK NOBODY STARTED. A #[Scheduled] method fires only under `schedule:work` or a cron-driven
 * `schedule:run`, and nothing in a fresh application starts either: `firefly:serve` serves, and a developer
 * who has written `#[Scheduled(fixedRate: '60s')]` on the method that advances every workflow tests a product
 * whose clock is stopped — a run that never leaves its first step, a schedule that reports a next-run time
 * and never fires — and reads the engine as broken. One real application lost a full verification pass to
 * exactly that, then wrote a shell script whose entire body was `exec php artisan schedule:work`.
 *
 * The listing is as much the point as the delegation. It is read off ScheduledManifest — the compiled
 * artifact or the in-process scan, whichever this boot uses — so a task that is missing from it was never
 * compiled, and that is visible on line one instead of being diagnosed later as "the scheduler is broken".
 * The cadence column comes from the same Cadence table ScheduleWiringPass wires with, so it says what will
 * happen (`7s` → every 10 seconds) rather than echoing the attribute.
 */
final class ScheduleCommand extends Command
{
    /** @var string */
    protected $signature = 'firefly:schedule {--once : run the due tasks once (schedule:run) instead of staying resident (schedule:work)}';

    /** @var string */
    protected $description = 'List every #[Scheduled] task with its real cadence, then run the scheduler (schedule:work, or schedule:run once with --once).';

    public function handle(ScheduledManifest $manifest): int
    {
        $this->report($manifest->all());

        $target = (bool) $this->option('once') ? 'schedule:run' : 'schedule:work';
        $this->line("  <fg=gray>Runtime </> {$target}");
        $this->newLine();

        return $this->call($target);
    }

    /**
     * @param  list<ScheduledDescriptor>  $tasks
     */
    private function report(array $tasks): void
    {
        $this->newLine();

        if ($tasks === []) {
            $this->line('  <fg=yellow>No #[Scheduled] task is compiled for this application.</> If you expected some, check firefly.scan.paths and run `php artisan firefly:cache`.');
            $this->newLine();

            return;
        }

        $this->line('  <fg=gray>Tasks   </> '.count($tasks).' scheduled');

        foreach ($tasks as $task) {
            $lock = $task->lockName !== null ? ' <fg=gray>(locked: '.$task->lockName.')</>' : '';
            $zone = $task->zone !== null ? ' <fg=gray>'.$task->zone.'</>' : '';
            $this->line(sprintf('  <options=bold>%s::%s</> — %s%s%s', $task->class, $task->method, Cadence::of($task)->describe(), $zone, $lock));
        }

        $this->newLine();
    }
}

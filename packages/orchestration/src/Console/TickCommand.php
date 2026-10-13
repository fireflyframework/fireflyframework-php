<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Console;

use Firefly\Orchestration\OrchestrationSettings;
use Firefly\Orchestration\Recovery\RecoveryService;
use Firefly\Orchestration\Workflow\Engine\WorkflowEngine;
use Illuminate\Console\Command;

/**
 * `php artisan firefly:orchestration:tick` — what the scheduled tick and sweep do, on demand or from cron in
 * an application without a scheduler: resume due WAITING workflows, then (unless --no-recovery, or recovery
 * is disabled) run the recovery sweep and the retention cleanup.
 */
final class TickCommand extends Command
{
    protected $signature = 'firefly:orchestration:tick {--no-recovery : only resume due workflows}';

    protected $description = 'Resume due workflows and run the orchestration recovery sweep.';

    public function handle(WorkflowEngine $workflows, RecoveryService $recovery, OrchestrationSettings $settings): int
    {
        $advanced = count($workflows->resumeDue());
        $this->components->info("Advanced {$advanced} waiting workflow(s).");

        if ($this->option('no-recovery') === true || ! $settings->recovery->enabled) {
            return self::SUCCESS;
        }

        $report = $recovery->recover();
        $this->components->info(sprintf(
            'Recovery: %d stale, %d resumed, %d compensated, %d failed, %d cleaned up.',
            count($report->stale), count($report->resumed), count($report->compensated), count($report->errors), $report->cleaned,
        ));
        foreach ($report->errors as $id => $error) {
            $this->components->warn("{$id}: {$error}");
        }

        return $report->errors === [] ? self::SUCCESS : self::FAILURE;
    }
}

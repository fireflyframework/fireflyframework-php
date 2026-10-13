<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Console;

use Firefly\EventSourcing\Projection\ProjectionRunner;
use Firefly\EventSourcing\Projection\ProjectionStatus;
use Illuminate\Console\Command;

/** `php artisan firefly:eventsourcing:projections`: every projection, its checkpoint, the global head and its lag. */
final class ListProjectionsCommand extends Command
{
    protected $signature = 'firefly:eventsourcing:projections';

    protected $description = 'List the projections with their checkpoint, the global head and their lag.';

    public function handle(ProjectionRunner $runner): int
    {
        $statuses = $runner->statuses();
        if ($statuses === []) {
            $this->components->info('No projections are registered.');

            return self::SUCCESS;
        }

        $this->table(['Projection', 'Mode', 'Position', 'Head', 'Lag', 'Status', 'Last error'], array_map(static fn (ProjectionStatus $status): array => [
            $status->name,
            $status->inline ? 'inline' : 'catch-up',
            (string) $status->position,
            (string) $status->head,
            (string) $status->lag,
            $status->lastError === null ? 'ok' : 'failed',
            $status->lastError ?? '',
        ], $statuses));

        return self::SUCCESS;
    }
}

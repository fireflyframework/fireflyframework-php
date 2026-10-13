<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Console;

use Firefly\EventSourcing\Exception\EventSourcingException;
use Firefly\EventSourcing\Projection\ProjectionRunner;
use Firefly\EventSourcing\Projection\ProjectionRunResult;
use Illuminate\Console\Command;

/**
 * `php artisan firefly:eventsourcing:projections:rebuild <projection>`: under the projection's lease, resets the
 * projection and its checkpoint in one transaction, then replays the whole global stream into it. This is also how an
 * inline projection (the event-sourced orchestration read model) is repaired or built the first time.
 */
final class RebuildProjectionCommand extends Command
{
    protected $signature = 'firefly:eventsourcing:projections:rebuild {projection : the projection to reset and replay from position 0}';

    protected $description = 'Reset a projection and replay the global event stream into it from position 0.';

    public function handle(ProjectionRunner $runner): int
    {
        try {
            $result = $runner->rebuild($this->argument('projection'));
        } catch (EventSourcingException $refused) {
            $this->components->error($refused->getMessage());

            return self::FAILURE;
        }

        if ($result->outcome !== ProjectionRunResult::CAUGHT_UP) {
            $this->components->error(sprintf('%s was not rebuilt: %s%s', $result->projection, $result->outcome, $result->error === null ? '' : ' ('.$result->error.')'));

            return self::FAILURE;
        }

        $this->components->info(sprintf('%s rebuilt: %d event(s) handled, at position %d', $result->projection, $result->handled, $result->toPosition));

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Console;

use Firefly\Orchestration\Core\Exception\OrchestrationException;
use Firefly\Orchestration\Dlq\DeadLetterRetrier;
use Illuminate\Console\Command;

/** `php artisan firefly:orchestration:dlq:retry <id>` — start the dead letter's definition again with its input. */
final class RetryDeadLetterCommand extends Command
{
    use OptionReader;

    protected $signature = 'firefly:orchestration:dlq:retry {id : the dead-letter id}';

    protected $description = 'Retry an orchestration dead letter.';

    public function handle(DeadLetterRetrier $retrier): int
    {
        try {
            $outcome = $retrier->retry($this->stringArgument('id') ?? '');
        } catch (OrchestrationException $refused) {
            $this->components->error($refused->getMessage());

            return self::FAILURE;
        }

        $line = "{$outcome->pattern->value} [{$outcome->name}] restarted as {$outcome->correlationId}: {$outcome->status->value}.";
        if ($outcome->success) {
            $this->components->info($line);

            return self::SUCCESS;
        }

        $this->components->error($line);

        return self::FAILURE;
    }
}

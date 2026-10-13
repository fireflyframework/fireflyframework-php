<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Console;

use Firefly\Orchestration\Persistence\ExecutionPersistenceProvider;
use Illuminate\Console\Command;

/** `php artisan firefly:orchestration:show <id>` — one execution's full checkpoint as JSON. */
final class ShowExecutionCommand extends Command
{
    use OptionReader;

    protected $signature = 'firefly:orchestration:show {id : the correlation id}';

    protected $description = 'Show one persisted orchestration execution.';

    public function handle(ExecutionPersistenceProvider $persistence): int
    {
        $id = $this->stringArgument('id') ?? '';
        $state = $persistence->findById($id);
        if ($state === null) {
            $this->components->error("No orchestration execution [{$id}] is persisted.");

            return self::FAILURE;
        }

        $this->line((string) json_encode($state->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

        return self::SUCCESS;
    }
}

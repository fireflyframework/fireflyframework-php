<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Console;

use Firefly\Orchestration\Core\Exception\OrchestrationException;
use Firefly\Orchestration\Workflow\Engine\WorkflowEngine;
use Illuminate\Console\Command;
use JsonException;

/** `php artisan firefly:orchestration:signal <id> <signal> [--payload=JSON]` — deliver a signal to a workflow. */
final class SignalWorkflowCommand extends Command
{
    use OptionReader;

    protected $signature = 'firefly:orchestration:signal
        {id : the workflow correlation id}
        {signal : the signal name}
        {--payload= : the payload, as JSON}';

    protected $description = 'Deliver a signal to a durable workflow.';

    public function handle(WorkflowEngine $workflows): int
    {
        $payload = null;
        $raw = $this->stringOption('payload');
        if ($raw !== null) {
            try {
                $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                $this->components->error("--payload is not JSON: {$error->getMessage()}");

                return self::FAILURE;
            }
        }

        try {
            $state = $workflows->signal($this->stringArgument('id') ?? '', $this->stringArgument('signal') ?? '', $payload);
        } catch (OrchestrationException $refused) {
            $this->components->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Execution {$state->correlationId} is now {$state->status->value}".($state->waitingOn === null ? '.' : " (waiting on {$state->waitingOn})."));

        return self::SUCCESS;
    }
}

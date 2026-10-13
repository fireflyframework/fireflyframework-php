<?php

declare(strict_types=1);

namespace Firefly\Callbacks\Console;

use Firefly\Callbacks\Delivery\CallbackDispatcher;
use Firefly\Callbacks\Execution\CallbackExecution;
use Firefly\Callbacks\Execution\CallbackStatus;
use Firefly\Callbacks\Execution\ExecutionLog;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;

/**
 * Sends logged deliveries again: the executions named by id, and/or with --failed the most recent failed ones. Each
 * is re-sent to its original URL with its ORIGINAL body, re-signed with a fresh timestamp, under the subscription's
 * current attempt budget and the endpoint's breaker; its attempts keep counting. A deactivated subscription is
 * refused (and its execution left as logged) unless --include-inactive is passed, which the output announces. Exits 1 if any replay did not end
 * delivered, so a scheduled replay surfaces in the scheduler's output.
 */
final class ReplayCallbacksCommand extends Command
{
    /** @var string */
    protected $signature = 'firefly:callbacks:replay {ids?* : execution ids, as firefly:callbacks:list --executions prints them} {--failed : replay the most recent failed deliveries} {--limit=50 : with --failed, how many} {--include-inactive : also replay to deactivated subscriptions}';

    /** @var string */
    protected $description = 'Send logged callback deliveries again.';

    public function handle(Container $container): int
    {
        if (! $container->bound(CallbackDispatcher::class) || ! $container->bound(ExecutionLog::class)) {
            $this->error('firefly/callbacks is not enabled (firefly.callbacks.enabled is false).');

            return self::FAILURE;
        }

        $log = $container->make(ExecutionLog::class);
        $dispatcher = $container->make(CallbackDispatcher::class);

        $ids = array_values($this->argument('ids'));
        $failed = (bool) $this->option('failed');

        if ($ids === [] && ! $failed) {
            $this->error('Name execution ids, or pass --failed.');

            return self::FAILURE;
        }

        /** @var array<string, CallbackExecution> $executions */
        $executions = [];
        foreach ($ids as $id) {
            $execution = $log->find($id);
            if ($execution === null) {
                $this->warn("No execution {$id}.");

                continue;
            }
            $executions[$execution->id] = $execution;
        }

        if ($failed) {
            foreach ($log->recent(max(1, (int) $this->option('limit')), CallbackStatus::Failed) as $execution) {
                $executions[$execution->id] = $execution;
            }
        }

        if ($executions === []) {
            $this->info('Nothing to replay.');

            return self::SUCCESS;
        }

        $includeInactive = (bool) $this->option('include-inactive');
        if ($includeInactive) {
            $this->warn('--include-inactive: inactive subscriptions are replayed too.');
        }

        $allDelivered = true;
        foreach ($executions as $execution) {
            $result = $dispatcher->replay($execution, $includeInactive);
            if ($result === null) {
                $this->warn("{$execution->id}: subscription {$execution->subscriptionId} no longer exists; skipped.");
                $allDelivered = false;

                continue;
            }

            $line = "{$result->id}: {$result->status->value} after {$result->attempts} attempt(s)".($result->lastError !== null ? " — {$result->lastError}" : '');
            if (! $includeInactive && $result->lastError === "subscription [{$execution->subscriptionId}] is inactive") {
                $line .= '; pass --include-inactive to replay it anyway';
            }
            if ($result->status === CallbackStatus::Delivered) {
                $this->info($line);
            } else {
                $this->warn($line);
                $allDelivered = false;
            }
        }

        return $allDelivered ? self::SUCCESS : self::FAILURE;
    }
}

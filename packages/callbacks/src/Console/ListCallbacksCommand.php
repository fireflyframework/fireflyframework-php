<?php

declare(strict_types=1);

namespace Firefly\Callbacks\Console;

use Firefly\Callbacks\Execution\CallbackStatus;
use Firefly\Callbacks\Execution\ExecutionLog;
use Firefly\Callbacks\Subscription\SubscriptionRegistry;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;

/**
 * The subscriptions (default), or with --executions the most recent deliveries — newest first, filtered by
 * --status and --subscription — with the full execution ids firefly:callbacks:replay takes.
 */
final class ListCallbacksCommand extends Command
{
    /** @var string */
    protected $signature = 'firefly:callbacks:list {--executions : list recent deliveries instead of subscriptions} {--status= : with --executions, only pending, delivered or failed} {--subscription= : with --executions, only this subscription id} {--limit=20 : with --executions, how many}';

    /** @var string */
    protected $description = 'List callback subscriptions, or the most recent callback deliveries.';

    public function handle(Container $container): int
    {
        if (! $container->bound(SubscriptionRegistry::class) || ! $container->bound(ExecutionLog::class)) {
            $this->error('firefly/callbacks is not enabled (firefly.callbacks.enabled is false).');

            return self::FAILURE;
        }

        if ((bool) $this->option('executions')) {
            return $this->executions($container->make(ExecutionLog::class));
        }

        $rows = [];
        foreach ($container->make(SubscriptionRegistry::class)->all() as $subscription) {
            $rows[] = [$subscription->id, $subscription->name, $subscription->url, implode(', ', $subscription->eventTypes), $subscription->active ? 'yes' : 'no', $subscription->secret !== null ? 'yes' : 'no'];
        }

        if ($rows === []) {
            $this->info('No callback subscriptions.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Name', 'URL', 'Event types', 'Active', 'Signed'], $rows);

        return self::SUCCESS;
    }

    private function executions(ExecutionLog $log): int
    {
        $statusOption = $this->option('status');
        $status = null;
        if (is_string($statusOption) && $statusOption !== '') {
            $status = CallbackStatus::tryFrom($statusOption);
            if ($status === null) {
                $this->error("--status must be pending, delivered or failed; got '{$statusOption}'.");

                return self::FAILURE;
            }
        }

        $subscription = $this->option('subscription');
        $rows = [];
        foreach ($log->recent(max(1, (int) $this->option('limit')), $status, is_string($subscription) && $subscription !== '' ? $subscription : null) as $execution) {
            $rows[] = [
                $execution->id,
                $execution->subscriptionId,
                $execution->eventType,
                $execution->status->value,
                (string) $execution->attempts,
                $execution->responseStatus === null ? '-' : (string) $execution->responseStatus,
                $execution->lastError ?? '',
                gmdate('Y-m-d H:i:s', $execution->updatedAt),
            ];
        }

        if ($rows === []) {
            $this->info('No callback deliveries.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Subscription', 'Event type', 'Status', 'Attempts', 'HTTP', 'Last error', 'Updated (UTC)'], $rows);

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Messaging\Console;

use Firefly\Messaging\DeadLetter\DeadLetterQuery;
use Firefly\Messaging\DeadLetter\DeadLetterStore;
use Firefly\Messaging\DeadLetter\ReplayableDeadLetterStore;
use Firefly\Messaging\MessageBrokerPort;
use Illuminate\Console\Command;
use Throwable;

/**
 * Republishes dead-lettered messages, oldest first, through the BOUND MessageBrokerPort to the topic they were
 * dead-lettered from (`x-original-topic`, else the dead-letter topic itself), value and key unchanged. The retry
 * bookkeeping headers (`x-original-topic`, `x-exception`) are dropped and `x-replayed-from: <entry id>` is added, so
 * a listener can tell a replay from a first delivery. Each entry that republished is removed unless --keep; one that
 * fails is reported and kept, and the command exits FAILURE. Replay is at-least-once, like firefly:eda:dead-letters:
 * replay: a crash between the publish and the removal replays that entry again next time.
 */
final class ReplayDeadLettersCommand extends Command
{
    /** @var string */
    protected $signature = 'firefly:messaging:dead-letters:replay {--id=* : replay only these entries} {--topic= : only entries of this topic (the dead-letter topic or the original one)} {--limit=100 : at most this many entries} {--dry-run : list what would be replayed and change nothing} {--keep : keep each entry after it is republished}';

    /** @var string */
    protected $description = 'Republish dead-lettered messages through the bound MessageBrokerPort, removing each one that republished.';

    public function handle(DeadLetterStore $store, MessageBrokerPort $broker): int
    {
        if (! $store instanceof ReplayableDeadLetterStore) {
            $this->error(sprintf('The bound messaging DeadLetterStore (%s) cannot select or remove entries. Bind a ReplayableDeadLetterStore, or drop the binding and set firefly.messaging.dead-letter.store=database.', get_debug_type($store)));

            return self::FAILURE;
        }

        $entries = $store->find($this->query());

        $replayed = 0;
        $failed = 0;
        foreach ($entries as $entry) {
            $message = $entry->message;
            $target = $message->headers['x-original-topic'] ?? $message->topic;
            $headers = array_diff_key($message->headers, ['x-original-topic' => true, 'x-exception' => true]);
            $headers['x-replayed-from'] = (string) $entry->id;

            if ($this->option('dry-run') === true) {
                $this->line(sprintf('would replay %s %s -> %s', $entry->id, $message->topic, $target));

                continue;
            }

            try {
                $broker->publish($target, $message->value, $message->key, $headers);
                if ($this->option('keep') !== true && $entry->id !== null) {
                    $store->remove($entry->id);
                }
                $replayed++;
                $this->line(sprintf('replayed %s %s -> %s', $entry->id, $message->topic, $target));
            } catch (Throwable $e) {
                $failed++;
                $this->error(sprintf('could not replay %s %s: %s', $entry->id, $message->topic, $e->getMessage()));
            }
        }

        $this->info(sprintf('firefly:messaging:dead-letters:replay — %d selected, %d replayed, %d failed.', count($entries), $replayed, $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function query(): DeadLetterQuery
    {
        $ids = [];
        foreach ((array) $this->option('id') as $id) {
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        $topic = $this->option('topic');
        $limit = $this->option('limit');

        return new DeadLetterQuery(
            $ids,
            is_string($topic) && $topic !== '' ? $topic : null,
            is_numeric($limit) ? max(1, (int) $limit) : 100,
        );
    }
}

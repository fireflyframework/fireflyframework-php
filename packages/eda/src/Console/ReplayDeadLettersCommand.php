<?php

declare(strict_types=1);

namespace Firefly\Eda\Console;

use Firefly\Eda\DeadLetter\DeadLetterQuery;
use Firefly\Eda\DeadLetter\DeadLetterStore;
use Firefly\Eda\DeadLetter\ReplayableDeadLetterStore;
use Firefly\Eda\EventPublisher;
use Illuminate\Console\Command;
use Throwable;

/**
 * Republishes dead-lettered envelopes, oldest first, through the BOUND EventPublisher — under an outbox provider that
 * is a new outbox row, under a broker provider a new message — to the route they were dead-lettered from
 * (`x-original-topic`, else the envelope's destination). The retry bookkeeping headers are dropped and
 * `x-replayed-from: <entry id>` is added, so a listener can tell a replay from a first delivery. Each entry that
 * republished is removed unless --keep; one that fails is reported and kept, and the command exits FAILURE. Replay is
 * at-least-once: a crash between the publish and the removal replays that entry again next time.
 */
final class ReplayDeadLettersCommand extends Command
{
    /** @var string */
    protected $signature = 'firefly:eda:dead-letters:replay {--id=* : replay only these entries} {--destination= : only entries dead-lettered from this destination} {--event-type= : only entries of this event type} {--limit=100 : at most this many entries} {--dry-run : list what would be replayed and change nothing} {--keep : keep each entry after it is republished}';

    /** @var string */
    protected $description = 'Republish dead-lettered eda envelopes through the bound EventPublisher, removing each one that republished.';

    public function handle(DeadLetterStore $store, EventPublisher $publisher): int
    {
        if (! $store instanceof ReplayableDeadLetterStore) {
            $this->error(sprintf('The bound DeadLetterStore (%s) cannot select or remove entries. Bind a ReplayableDeadLetterStore, or drop the binding and set firefly.eda.dead-letter.store=database.', get_debug_type($store)));

            return self::FAILURE;
        }

        $entries = $store->find($this->query());

        $replayed = 0;
        $failed = 0;
        foreach ($entries as $entry) {
            $envelope = $entry->envelope;
            $destination = $envelope->headers['x-original-topic'] ?? $envelope->destination;
            $headers = array_diff_key($envelope->headers, ['x-original-topic' => true, 'x-exception' => true]);
            $headers['x-replayed-from'] = (string) $entry->id;

            if ($this->option('dry-run') === true) {
                $this->line(sprintf('would replay %s %s -> %s', $entry->id, $envelope->eventType, $destination));

                continue;
            }

            try {
                $publisher->publish($destination, $envelope->eventType, $envelope->payload, $headers);
                if ($this->option('keep') !== true && $entry->id !== null) {
                    $store->remove($entry->id);
                }
                $replayed++;
                $this->line(sprintf('replayed %s %s -> %s', $entry->id, $envelope->eventType, $destination));
            } catch (Throwable $e) {
                $failed++;
                $this->error(sprintf('could not replay %s %s: %s', $entry->id, $envelope->eventType, $e->getMessage()));
            }
        }

        $this->info(sprintf('firefly:eda:dead-letters:replay — %d selected, %d replayed, %d failed.', count($entries), $replayed, $failed));

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

        $destination = $this->option('destination');
        $eventType = $this->option('event-type');
        $limit = $this->option('limit');

        return new DeadLetterQuery(
            $ids,
            is_string($destination) && $destination !== '' ? $destination : null,
            is_string($eventType) && $eventType !== '' ? $eventType : null,
            is_numeric($limit) ? max(1, (int) $limit) : 100,
        );
    }
}

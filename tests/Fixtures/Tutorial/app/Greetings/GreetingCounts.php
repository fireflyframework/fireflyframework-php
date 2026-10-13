<?php

declare(strict_types=1);

namespace App\Greetings;

use Firefly\Container\Attributes\Component;
use Firefly\EventSourcing\Event\StoredEvent;
use Firefly\EventSourcing\Projection\Projection;
use Illuminate\Support\Facades\DB;

#[Component]
final class GreetingCounts implements Projection
{
    public const string TABLE = 'greeting_counts';

    public function name(): string
    {
        return 'greeting.counts';
    }

    public function handles(): array
    {
        return ['greeting.said'];
    }

    public function handle(StoredEvent $event): void
    {
        $message = $event->payload['message'] ?? '';
        $message = is_string($message) ? $message : '';

        if (DB::table(self::TABLE)->where('name', $event->aggregateId)->exists()) {
            DB::table(self::TABLE)->where('name', $event->aggregateId)->increment('times', 1, ['last_message' => $message]);

            return;
        }

        DB::table(self::TABLE)->insert(['name' => $event->aggregateId, 'times' => 1, 'last_message' => $message]);
    }

    public function reset(): void
    {
        DB::table(self::TABLE)->delete();
    }

    /** @return array{name: string, times: int, lastMessage: string}|null */
    public static function find(string $name): ?array
    {
        $row = DB::table(self::TABLE)->where('name', $name)->first();
        if ($row === null) {
            return null;
        }

        $values = (array) $row;
        $times = $values['times'] ?? 0;
        $message = $values['last_message'] ?? '';

        return ['name' => $name, 'times' => is_numeric($times) ? (int) $times : 0, 'lastMessage' => is_string($message) ? $message : ''];
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Messaging\DeadLetter;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Firefly\Messaging\Message;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

/**
 * Messaging dead letters that survive the process: one firefly_messaging_dead_letters row per message a
 * #[MessageListener] exhausted, on any Laravel database connection (firefly.messaging.dead-letter.store=database,
 * firefly.messaging.dead-letter.connection). The row keeps the dead-letter topic the message was re-keyed to, its
 * original topic (`x-original-topic`, also a column so it can be selected), the key, the headers, the cause and the
 * instant — and the raw value base64-encoded (see DeadLetterSchema for why). Text that only describes the failure
 * (the exception message, and the headers' JSON) is made valid UTF-8 before it is written, so a listener that threw
 * with raw bytes in its message still has its message kept rather than lost to an encoding error. The id is a UUID
 * made in PHP, the instant the application clock in UTC at millisecond precision; the connection is resolved per
 * call, never at construction, so binding the store opens nothing at boot.
 */
final class DatabaseDeadLetterStore implements ReplayableDeadLetterStore
{
    /**
     * @param  (Closure(): DateTimeImmutable)|null  $clock
     */
    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly ?string $connection = null,
        private readonly ?Closure $clock = null,
    ) {}

    public function store(Message $message, Throwable $cause): void
    {
        $now = $this->clock === null ? new DateTimeImmutable : ($this->clock)();

        $this->db()->table(DeadLetterSchema::TABLE)->insert([
            'id' => (string) Str::uuid(),
            'topic' => $message->topic,
            'original_topic' => $message->headers['x-original-topic'] ?? null,
            'message_key' => $message->key,
            'value' => base64_encode($message->value),
            'headers' => json_encode($message->headers === [] ? new stdClass : $message->headers, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            'exception_class' => $cause::class,
            'exception_message' => mb_scrub($cause->getMessage(), 'UTF-8'),
            'dead_lettered_at' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v'),
        ]);
    }

    /**
     * @return list<DeadLetterEntry>
     */
    public function all(): array
    {
        return $this->find(new DeadLetterQuery(limit: null));
    }

    public function find(DeadLetterQuery $query): array
    {
        $select = $this->db()->table(DeadLetterSchema::TABLE)->orderBy('dead_lettered_at')->orderBy('id');

        if ($query->ids !== []) {
            $select->whereIn('id', $query->ids);
        }
        if ($query->topic !== null) {
            $topic = $query->topic;
            $select->where(static function (Builder $where) use ($topic): void {
                $where->where('topic', $topic)->orWhere('original_topic', $topic);
            });
        }
        if ($query->limit !== null) {
            $select->limit(max(1, $query->limit));
        }

        $entries = [];
        foreach ($select->get() as $row) {
            $entries[] = self::entry($row);
        }

        return $entries;
    }

    public function remove(string $id): bool
    {
        return $this->db()->table(DeadLetterSchema::TABLE)->where('id', $id)->delete() === 1;
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->connection);
    }

    private static function entry(object $row): DeadLetterEntry
    {
        $columns = get_object_vars($row);
        $value = base64_decode(self::text($columns['value'] ?? null), true);
        $key = $columns['message_key'] ?? null;
        $at = self::text($columns['dead_lettered_at'] ?? null);

        $headers = [];
        $json = self::text($columns['headers'] ?? null);
        $map = $json === '' ? [] : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        foreach (is_array($map) ? $map : [] as $name => $header) {
            if (is_scalar($header)) {
                $headers[(string) $name] = (string) $header;
            }
        }

        return new DeadLetterEntry(
            new Message(self::text($columns['topic'] ?? null), $value === false ? '' : $value, is_scalar($key) ? (string) $key : null, $headers),
            self::text($columns['exception_class'] ?? null),
            self::text($columns['exception_message'] ?? null),
            self::text($columns['id'] ?? null),
            $at === '' ? null : new DateTimeImmutable($at, new DateTimeZone('UTC')),
        );
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}

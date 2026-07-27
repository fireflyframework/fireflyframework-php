<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres\Outbox;

/**
 * Narrows the mixed-typed columns of a firefly_eda_outbox query-builder row (stdClass properties carry no static
 * type) to the concrete scalar/array shapes the terminal consumer and the relay need — one place, level-max clean,
 * reflection-free. asMap()/asStringMap() keep JSON_THROW_ON_ERROR so a corrupt payload/headers column fails loud
 * (caught by the relay's per-row try/catch, or surfaced by the consumer poll) rather than being silently dropped.
 */
final class OutboxRow
{
    public static function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    public static function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /** @return array<string, mixed> */
    public static function asMap(mixed $json): array
    {
        if (! is_string($json)) {
            return [];
        }

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            return [];
        }

        $map = [];
        foreach ($decoded as $key => $value) {
            $map[(string) $key] = $value;
        }

        return $map;
    }

    /** @return array<string, string> */
    public static function asStringMap(mixed $json): array
    {
        $map = [];
        foreach (self::asMap($json) as $key => $value) {
            if (is_scalar($value)) {
                $map[$key] = (string) $value;
            }
        }

        return $map;
    }
}

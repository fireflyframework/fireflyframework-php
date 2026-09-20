<?php

declare(strict_types=1);

namespace Firefly\Data\Repository\Example;

/**
 * How a STRING probe value is compared — Spring's ExampleMatcher.StringMatcher without REGEX (no portable SQL
 * for it). EXACT is `=`; the other three are LIKE with the value placed literally: pattern() escapes `!`, `%`
 * and `_` with `!`, and the fragment Example emits carries `ESCAPE '!'` — the one escape spelling that reads the
 * same on sqlite, mysql, pgsql and sqlsrv (a backslash literal does not: mysql consumes it inside the string).
 */
enum StringMatcher
{
    case EXACT;
    case CONTAINING;
    case STARTING;
    case ENDING;

    public function pattern(string $value): string
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);

        return match ($this) {
            self::EXACT => $escaped,
            self::CONTAINING => '%'.$escaped.'%',
            self::STARTING => $escaped.'%',
            self::ENDING => '%'.$escaped,
        };
    }
}

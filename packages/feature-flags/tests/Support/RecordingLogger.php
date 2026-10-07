<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/** Keeps "level: interpolated message" lines so a test can count WARNs. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    /**
     * @param  mixed  $level
     * @param  array<array-key, mixed>  $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $replacements = [];
        foreach ($context as $key => $value) {
            $replacements['{'.$key.'}'] = is_scalar($value) || $value === null ? (string) $value : json_encode($value);
        }

        $this->lines[] = (is_string($level) ? $level : 'log').': '.strtr((string) $message, $replacements);
    }

    public function count(string $level, string $needle): int
    {
        return count(array_filter($this->lines, static fn (string $line): bool => str_starts_with($line, $level.':') && str_contains($line, $needle)));
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Scheduling\Tests\Support;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Stringable;

/**
 * A PSR-3 logger that keeps every record, for the scheduling flows whose contract is "this degrades AND SAYS
 * SO": an initial-delay anchor that does not persist, and an anchor store that cannot outlive a cron-driven
 * `schedule:run`. Both are behaviours whose whole point is that they are not silent, so the line an operator
 * would grep for is asserted on rather than assumed.
 */
final class RecordingLogger implements LoggerInterface
{
    use LoggerTrait;

    /** @var list<array{level: string, message: string}> */
    public array $records = [];

    /**
     * @param  mixed  $level
     * @param  array<string, mixed>  $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => is_scalar($level) ? (string) $level : 'unknown', 'message' => (string) $message];
    }

    /**
     * @return list<array{level: string, message: string}> the records whose message mentions the needle
     */
    public function mentioning(string $needle): array
    {
        return array_values(array_filter($this->records, static fn (array $record): bool => str_contains($record['message'], $needle)));
    }
}

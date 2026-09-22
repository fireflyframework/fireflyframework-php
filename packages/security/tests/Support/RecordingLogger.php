<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Support;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Stringable;

/**
 * A PSR-3 logger that keeps every record, for the flows whose contract is "this falls back AND SAYS SO" — a
 * `form_login.view` that does not render. Bound as `Psr\Log\LoggerInterface` before boot it is what every
 * guarded `bound()/make()` resolution in the framework hands to its beans, so the line an operator would grep
 * for can be asserted on instead of assumed.
 */
final class RecordingLogger implements LoggerInterface
{
    use LoggerTrait;

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param  mixed  $level
     * @param  array<string, mixed>  $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => is_scalar($level) ? (string) $level : 'unknown', 'message' => (string) $message, 'context' => $context];
    }

    /** @return list<array{level: string, message: string, context: array<string, mixed>}> the records whose message mentions the needle */
    public function mentioning(string $needle): array
    {
        return array_values(array_filter($this->records, static fn (array $record): bool => str_contains($record['message'], $needle)));
    }

    public function reset(): void
    {
        $this->records = [];
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/** A PSR-3 logger that keeps what it was told, for asserting that a degraded path says so. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string}> */
    public array $records = [];

    /**
     * @param  array<array-key, mixed>  $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => is_string($level) ? $level : 'unknown', 'message' => (string) $message];
    }
}

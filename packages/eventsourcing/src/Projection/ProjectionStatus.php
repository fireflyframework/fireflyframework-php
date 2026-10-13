<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Projection;

use DateTimeImmutable;

/**
 * One projection as the list command and the health component show it: its checkpoint, the global head and the
 * distance between them. An inline projection's lag is 0 by construction (its writer keeps it current).
 */
final readonly class ProjectionStatus
{
    public function __construct(
        public string $name,
        public bool $inline,
        public int $position,
        public int $head,
        public int $lag,
        public ?string $lastError = null,
        public ?DateTimeImmutable $failedAt = null,
        public ?DateTimeImmutable $updatedAt = null,
    ) {}

    public function state(): string
    {
        return match (true) {
            $this->lastError !== null => 'failed',
            $this->inline => 'inline',
            default => 'ok',
        };
    }
}

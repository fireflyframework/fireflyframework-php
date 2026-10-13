<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Projection;

/**
 * What one catch-up of one projection did: from which position to which, how many events it handed the projection,
 * and how it ended.
 *
 *  - `caught-up`: it reached the head.
 *  - `leased-elsewhere`: another node holds the projection's lease.
 *  - `fenced`: another runner moved the checkpoint first, so this batch rolled back.
 *  - `failed`: the projection threw, the batch rolled back, and `error` says why; the next cycle retries it.
 */
final readonly class ProjectionRunResult
{
    public const string CAUGHT_UP = 'caught-up';

    public const string LEASED_ELSEWHERE = 'leased-elsewhere';

    public const string FENCED = 'fenced';

    public const string FAILED = 'failed';

    public function __construct(
        public string $projection,
        public string $outcome,
        public int $fromPosition,
        public int $toPosition,
        public int $handled = 0,
        public ?string $error = null,
    ) {}

    public function failed(): bool
    {
        return $this->outcome === self::FAILED;
    }
}

<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Snapshot;

use Firefly\EventSourcing\EventSourcingSettings;

/** When the repository snapshots: each time a save moves the version across a multiple of `threshold`. */
final readonly class SnapshotPolicy
{
    public function __construct(
        public bool $enabled = true,
        public int $threshold = 50,
        public int $keepCount = 3,
    ) {}

    public static function fromSettings(EventSourcingSettings $settings): self
    {
        return new self($settings->snapshotEnabled, $settings->snapshotThreshold, $settings->snapshotKeepCount);
    }

    public function due(int $before, int $after): bool
    {
        return $this->enabled && $this->threshold > 0 && intdiv($after, $this->threshold) > intdiv($before, $this->threshold);
    }
}

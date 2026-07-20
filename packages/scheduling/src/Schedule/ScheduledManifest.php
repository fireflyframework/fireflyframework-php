<?php

declare(strict_types=1);

namespace Firefly\Scheduling\Schedule;

use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * The compiled, reflection-free scheduled-task source the ScheduleWiringPass reads at boot. Loaded via
 * require+map; the descriptors are pure arrays, so the whole manifest is a plain PHP array literal. Mirrors the
 * container ComponentManifest / web RouteManifest idiom.
 *
 * @phpstan-import-type ScheduledRow from ScheduledDescriptor
 */
final class ScheduledManifest
{
    /**
     * @param  list<ScheduledDescriptor>  $tasks
     */
    public function __construct(private readonly array $tasks) {}

    /**
     * @param  array<int, ScheduledRow>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(array_map(
            static fn (array $row): ScheduledDescriptor => ScheduledDescriptor::fromArray($row),
            array_values($data),
        ));
    }

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            throw new ConfigurationException("Scheduled manifest not found at {$path}. Run the scheduled scan first.");
        }

        /** @var mixed $data */
        $data = require $path;
        if (! is_array($data)) {
            throw new ConfigurationException("Scheduled manifest at {$path} did not return an array.");
        }

        /** @var array<int, ScheduledRow> $data */
        return self::fromArray($data);
    }

    /**
     * @return list<ScheduledDescriptor>
     */
    public function all(): array
    {
        return $this->tasks;
    }
}

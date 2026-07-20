<?php

declare(strict_types=1);

namespace Firefly\Scheduling\Schedule;

/**
 * A single compiled scheduled task: the target class/method plus its trigger (exactly one of cron/fixedRate/
 * fixedDelay), optional initialDelay/zone, and the resolved lock name + ttl. Every field is a scalar-or-null so
 * the manifest var_exports as a plain array literal (no closures/objects), loaded by require+map in production.
 *
 * @phpstan-type ScheduledRow array{class: string, method: string, cron: string|null, fixedRate: string|null, fixedDelay: string|null, initialDelay: string|null, zone: string|null, lockName: string|null, lockTtl: string|null}
 */
final readonly class ScheduledDescriptor
{
    public function __construct(
        public string $class,
        public string $method,
        public ?string $cron = null,
        public ?string $fixedRate = null,
        public ?string $fixedDelay = null,
        public ?string $initialDelay = null,
        public ?string $zone = null,
        public ?string $lockName = null,
        public ?string $lockTtl = null,
    ) {}

    /**
     * @return ScheduledRow
     */
    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'method' => $this->method,
            'cron' => $this->cron,
            'fixedRate' => $this->fixedRate,
            'fixedDelay' => $this->fixedDelay,
            'initialDelay' => $this->initialDelay,
            'zone' => $this->zone,
            'lockName' => $this->lockName,
            'lockTtl' => $this->lockTtl,
        ];
    }

    /**
     * @param  ScheduledRow  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['class'],
            $data['method'],
            $data['cron'],
            $data['fixedRate'],
            $data['fixedDelay'],
            $data['initialDelay'],
            $data['zone'],
            $data['lockName'],
            $data['lockTtl'],
        );
    }
}

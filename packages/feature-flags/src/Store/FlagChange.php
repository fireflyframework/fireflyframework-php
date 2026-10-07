<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Store;

use DateTimeImmutable;
use DateTimeZone;

final readonly class FlagChange
{
    public const string PUT = 'put';

    public const string DELETE = 'delete';

    /**
     * @param  array<array-key, mixed>|null  $definition
     * @param  array<array-key, mixed>|null  $previous
     */
    public function __construct(
        public int $id,
        public string $key,
        public string $action,
        public ?array $definition,
        public ?array $previous,
        public ?string $actor,
        public DateTimeImmutable $changedAt,
    ) {}

    /** @return array{id: int, action: string, actor: ?string, changedAt: string} */
    public function toHistoryRow(): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'actor' => $this->actor,
            'changedAt' => $this->changedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}

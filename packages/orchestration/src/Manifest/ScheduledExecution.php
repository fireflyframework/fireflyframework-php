<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Manifest;

use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\Rows;

/**
 * A compiled #[ScheduledSaga] / #[ScheduledTcc] / #[ScheduledWorkflow]: exactly one of cron, fixedRate or
 * fixedDelay (durations as the framework grammar, `'60000ms'`), the zone, the input to start with.
 */
final readonly class ScheduledExecution
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public ExecutionPattern $pattern,
        public string $name,
        public ?string $cron = null,
        public ?string $zone = null,
        public ?string $fixedRate = null,
        public ?string $fixedDelay = null,
        public array $input = [],
        public string $description = '',
    ) {}

    public function lockName(int $index): string
    {
        return "firefly:orchestration:scheduled:{$this->pattern->value}:{$this->name}:{$index}";
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'pattern' => $this->pattern->value, 'name' => $this->name, 'cron' => $this->cron, 'zone' => $this->zone,
            'fixedRate' => $this->fixedRate, 'fixedDelay' => $this->fixedDelay, 'input' => $this->input, 'description' => $this->description,
        ];
    }

    /** @param array<array-key, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            ExecutionPattern::tryFrom(Rows::string($row, 'pattern')) ?? ExecutionPattern::Saga,
            Rows::string($row, 'name'),
            Rows::nullableString($row, 'cron'),
            Rows::nullableString($row, 'zone'),
            Rows::nullableString($row, 'fixedRate'),
            Rows::nullableString($row, 'fixedDelay'),
            Rows::map($row, 'input'),
            Rows::string($row, 'description'),
        );
    }
}

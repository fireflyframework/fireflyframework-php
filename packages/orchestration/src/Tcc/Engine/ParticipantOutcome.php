<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tcc\Engine;

use Firefly\Orchestration\Core\StepStatus;
use Firefly\Orchestration\Persistence\StateSerializer;
use Throwable;

/** One participant of a TccResult (jafly TccResult.ParticipantOutcome); `attempts` counts its try attempts. */
final readonly class ParticipantOutcome
{
    public function __construct(
        public string $id,
        public StepStatus $status,
        public mixed $tryResult,
        public ?Throwable $error,
        public int $attempts,
    ) {}

    public function tried(): bool
    {
        return in_array($this->status, [StepStatus::Done, StepStatus::Confirmed, StepStatus::ConfirmFailed, StepStatus::Canceled, StepStatus::CancelFailed], true);
    }

    public function confirmed(): bool
    {
        return $this->status === StepStatus::Confirmed;
    }

    public function canceled(): bool
    {
        return $this->status === StepStatus::Canceled;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id, 'status' => $this->status->value, 'tryResult' => StateSerializer::normalize($this->tryResult, 'tryResult'),
            'error' => $this->error?->getMessage(), 'attempts' => $this->attempts,
        ];
    }
}

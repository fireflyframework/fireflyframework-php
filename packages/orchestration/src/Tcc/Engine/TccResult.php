<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tcc\Engine;

use DateTimeImmutable;
use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\TccPhase;
use Firefly\Orchestration\Persistence\ExecutionState;
use Firefly\Orchestration\Report\ExecutionReport;
use Firefly\Orchestration\Tcc\Registry\TccDefinition;
use Throwable;

/**
 * What TccEngine::execute() returns (jafly TccResult): CONFIRMED, CANCELED or FAILED, which participant
 * failed in which phase, and each participant's try result. `success` is true when CONFIRMED, or when an
 * #[OnTccError(suppressError: true)] suppressed the failure.
 */
final readonly class TccResult
{
    /**
     * @param  array<string, ParticipantOutcome>  $participants
     */
    public function __construct(
        public string $tccName,
        public string $correlationId,
        public ExecutionStatus $status,
        public bool $success,
        public array $participants,
        public ?string $failedParticipantId,
        public ?TccPhase $failedPhase,
        public ?Throwable $error,
        public ?ExecutionReport $report,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $completedAt,
    ) {}

    public static function from(TccDefinition $tcc, ExecutionContext $context, TccRunOutcome $outcome, bool $success, ?ExecutionReport $report, DateTimeImmutable $now): self
    {
        $participants = [];
        foreach ($tcc->participantIds() as $id) {
            $participants[$id] = new ParticipantOutcome($id, $context->stepStatus($id), $context->tryResult($id), $context->stepError($id), $context->attempts($id));
        }

        return new self($tcc->name, $context->correlationId, $outcome->status, $success, $participants, $outcome->failedParticipantId, $outcome->failedPhase, $outcome->error, $report, $context->startedAt(), $now);
    }

    public function isConfirmed(): bool
    {
        return $this->status === ExecutionStatus::Confirmed;
    }

    public function isCanceled(): bool
    {
        return $this->status === ExecutionStatus::Canceled;
    }

    public function isFailed(): bool
    {
        return $this->status === ExecutionStatus::Failed;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function tryResultOf(string $participantId): mixed
    {
        return ($this->participants[$participantId] ?? null)?->tryResult;
    }

    /** @return array<string, mixed> */
    public function tryResults(): array
    {
        $results = [];
        foreach ($this->participants as $id => $participant) {
            if ($participant->tryResult !== null) {
                $results[$id] = $participant->tryResult;
            }
        }

        return $results;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tccName' => $this->tccName,
            'correlationId' => $this->correlationId,
            'status' => $this->status->value,
            'success' => $this->success,
            'failedParticipantId' => $this->failedParticipantId,
            'failedPhase' => $this->failedPhase?->value,
            'error' => $this->error?->getMessage(),
            'startedAt' => ExecutionState::instant($this->startedAt),
            'completedAt' => ExecutionState::instant($this->completedAt),
            'participants' => array_map(static fn (ParticipantOutcome $participant): array => $participant->toArray(), $this->participants),
            'report' => $this->report?->toArray(),
        ];
    }
}

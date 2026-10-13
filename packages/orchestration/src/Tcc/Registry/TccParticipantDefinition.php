<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tcc\Registry;

use Firefly\Orchestration\Core\Rows;
use Firefly\Orchestration\Core\StepOptions;
use Firefly\Orchestration\Core\TccPhase;
use Firefly\Orchestration\Saga\Registry\StepEventDefinition;

/**
 * One participant (jafly TccParticipantDefinition). options() resolves a phase's effective StepOptions the way
 * jafly does: the phase's own value, else the participant's timeout, else the TCC's, else
 * `firefly.orchestration.tcc.default-timeout`; retries are the phase's own, else the TCC's maxRetries when
 * retryEnabled, else none; backoff the phase's, else the TCC's.
 */
final readonly class TccParticipantDefinition
{
    public function __construct(
        public string $id,
        public int $order,
        public TccPhaseDefinition $try,
        public TccPhaseDefinition $confirm,
        public TccPhaseDefinition $cancel,
        public int $timeoutMs = -1,
        public bool $optional = false,
        public bool $jitter = false,
        public float $jitterFactor = 0.0,
        public ?StepEventDefinition $event = null,
    ) {}

    public function phase(TccPhase $phase): TccPhaseDefinition
    {
        return match ($phase) {
            TccPhase::Try => $this->try,
            TccPhase::Confirm => $this->confirm,
            TccPhase::Cancel => $this->cancel,
        };
    }

    public function options(TccPhase $phase, TccDefinition $tcc, float $defaultTimeoutSeconds): StepOptions
    {
        $definition = $this->phase($phase);
        $timeout = match (true) {
            $definition->timeoutMs >= 0 => $definition->timeoutMs,
            $this->timeoutMs >= 0 => $this->timeoutMs,
            $tcc->timeoutMs >= 0 => $tcc->timeoutMs,
            default => (int) round($defaultTimeoutSeconds * 1000),
        };
        $retry = $definition->retry >= 0 ? $definition->retry : ($tcc->retryEnabled ? $tcc->maxRetries : 0);
        $backoff = $definition->backoffMs >= 0 ? $definition->backoffMs : $tcc->backoffMs;

        return StepOptions::of($retry, $backoff, $timeout, $this->jitter, $this->jitterFactor);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id, 'order' => $this->order, 'try' => $this->try->toArray(), 'confirm' => $this->confirm->toArray(),
            'cancel' => $this->cancel->toArray(), 'timeoutMs' => $this->timeoutMs, 'optional' => $this->optional,
            'jitter' => $this->jitter, 'jitterFactor' => $this->jitterFactor, 'event' => $this->event?->toArray(),
        ];
    }

    /** @param array<array-key, mixed> $row */
    public static function fromArray(array $row): self
    {
        $event = Rows::nullableMap($row, 'event');

        return new self(
            id: Rows::string($row, 'id'),
            order: Rows::int($row, 'order'),
            try: TccPhaseDefinition::fromArray(Rows::map($row, 'try')),
            confirm: TccPhaseDefinition::fromArray(Rows::map($row, 'confirm')),
            cancel: TccPhaseDefinition::fromArray(Rows::map($row, 'cancel')),
            timeoutMs: Rows::int($row, 'timeoutMs', -1),
            optional: Rows::bool($row, 'optional'),
            jitter: Rows::bool($row, 'jitter'),
            jitterFactor: Rows::float($row, 'jitterFactor'),
            event: $event === null ? null : StepEventDefinition::fromArray($event),
        );
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\Rows;
use Firefly\Orchestration\Core\StepStatus;
use Firefly\Orchestration\Core\TccPhase;

/**
 * One checkpoint of one execution — what every ExecutionPersistenceProvider stores and every recovery reads
 * (jafly ExecutionState, widened with the TCC phase and the workflow's signals, timers, children and search
 * attributes so a single row resumes any of the three patterns). Immutable and JSON-safe by construction:
 * ExecutionContext::toState() normalizes every value through StateSerializer, so toArray() is lossless and
 * fromArray(toArray()) is the identity.
 *
 * `wakeAt` is the earliest instant a WAITING workflow needs the tick to look at it again (its earliest timer or
 * its deadline); it is what the database provider indexes, so the tick reads due rows, not every row.
 */
final readonly class ExecutionState
{
    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $stepResults
     * @param  array<string, StepStatus>  $stepStatuses
     * @param  array<string, int>  $stepAttempts
     * @param  array<string, int>  $stepLatenciesMs
     * @param  array<string, mixed>  $stepInputs
     * @param  list<string>  $completionOrder
     * @param  array<string, mixed>  $compensationResults
     * @param  array<string, string>  $compensationErrors
     * @param  array<string, string>  $stepErrors
     * @param  array<string, mixed>  $tryResults
     * @param  list<string>  $idempotencyKeys
     * @param  list<list<string>>  $topologyLayers
     * @param  array<string, string|int|float|bool>  $searchAttributes
     * @param  array<string, mixed>  $signals
     * @param  array<string, string>  $timers  timer id => fire-at instant (UTC, see instant())
     * @param  array<string, string>  $children  step id => child correlation id
     * @param  array<string, mixed>|null  $report
     */
    public function __construct(
        public string $correlationId,
        public string $executionName,
        public ExecutionPattern $pattern,
        public ExecutionStatus $status,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $updatedAt,
        public array $input = [],
        public array $headers = [],
        public array $variables = [],
        public array $stepResults = [],
        public array $stepStatuses = [],
        public array $stepAttempts = [],
        public array $stepLatenciesMs = [],
        public array $stepInputs = [],
        public array $completionOrder = [],
        public array $compensationResults = [],
        public array $compensationErrors = [],
        public array $stepErrors = [],
        public array $tryResults = [],
        public ?TccPhase $currentPhase = null,
        public array $idempotencyKeys = [],
        public array $topologyLayers = [],
        public ?string $failureReason = null,
        public ?DateTimeImmutable $completedAt = null,
        public ?DateTimeImmutable $wakeAt = null,
        public array $searchAttributes = [],
        public array $signals = [],
        public array $timers = [],
        public ?string $waitingOn = null,
        public ?string $parentCorrelationId = null,
        public array $children = [],
        public ?array $report = null,
    ) {}

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function stepStatus(string $stepId): StepStatus
    {
        return $this->stepStatuses[$stepId] ?? StepStatus::Pending;
    }

    public function withStatus(ExecutionStatus $status, DateTimeImmutable $now): self
    {
        $data = $this->toArray();
        $data['status'] = $status->value;
        $data['updatedAt'] = self::instant($now);
        $data['completedAt'] = $status->isTerminal() ? self::instant($now) : null;
        if ($status !== ExecutionStatus::Waiting) {
            $data['wakeAt'] = null;
        }

        return self::fromArray($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'correlationId' => $this->correlationId,
            'executionName' => $this->executionName,
            'pattern' => $this->pattern->value,
            'status' => $this->status->value,
            'startedAt' => self::instant($this->startedAt),
            'updatedAt' => self::instant($this->updatedAt),
            'completedAt' => $this->completedAt === null ? null : self::instant($this->completedAt),
            'wakeAt' => $this->wakeAt === null ? null : self::instant($this->wakeAt),
            'input' => $this->input,
            'headers' => $this->headers,
            'variables' => $this->variables,
            'stepResults' => $this->stepResults,
            'stepStatuses' => array_map(static fn (StepStatus $status): string => $status->value, $this->stepStatuses),
            'stepAttempts' => $this->stepAttempts,
            'stepLatenciesMs' => $this->stepLatenciesMs,
            'stepInputs' => $this->stepInputs,
            'completionOrder' => $this->completionOrder,
            'compensationResults' => $this->compensationResults,
            'compensationErrors' => $this->compensationErrors,
            'stepErrors' => $this->stepErrors,
            'tryResults' => $this->tryResults,
            'currentPhase' => $this->currentPhase?->value,
            'idempotencyKeys' => $this->idempotencyKeys,
            'topologyLayers' => $this->topologyLayers,
            'failureReason' => $this->failureReason,
            'searchAttributes' => $this->searchAttributes,
            'signals' => $this->signals,
            'timers' => $this->timers,
            'waitingOn' => $this->waitingOn,
            'parentCorrelationId' => $this->parentCorrelationId,
            'children' => $this->children,
            'report' => $this->report,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $statuses = [];
        foreach (Rows::stringMap($data, 'stepStatuses') as $step => $status) {
            $statuses[$step] = StepStatus::tryFrom($status) ?? StepStatus::Pending;
        }

        $layers = [];
        foreach (Rows::list($data, 'topologyLayers') as $layer) {
            if (is_array($layer)) {
                $layers[] = Rows::strings(['layer' => $layer], 'layer');
            }
        }

        $attributes = [];
        foreach (Rows::map($data, 'searchAttributes') as $key => $value) {
            if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
                $attributes[$key] = $value;
            }
        }

        $completedAt = Rows::nullableString($data, 'completedAt');
        $wakeAt = Rows::nullableString($data, 'wakeAt');
        $phase = Rows::nullableString($data, 'currentPhase');

        return new self(
            correlationId: Rows::string($data, 'correlationId'),
            executionName: Rows::string($data, 'executionName'),
            pattern: ExecutionPattern::tryFrom(Rows::string($data, 'pattern')) ?? ExecutionPattern::Saga,
            status: ExecutionStatus::tryFrom(Rows::string($data, 'status')) ?? ExecutionStatus::Pending,
            startedAt: self::parseInstant(Rows::string($data, 'startedAt', 'now')),
            updatedAt: self::parseInstant(Rows::string($data, 'updatedAt', 'now')),
            input: Rows::map($data, 'input'),
            headers: Rows::stringMap($data, 'headers'),
            variables: Rows::map($data, 'variables'),
            stepResults: Rows::map($data, 'stepResults'),
            stepStatuses: $statuses,
            stepAttempts: Rows::intMap($data, 'stepAttempts'),
            stepLatenciesMs: Rows::intMap($data, 'stepLatenciesMs'),
            stepInputs: Rows::map($data, 'stepInputs'),
            completionOrder: Rows::strings($data, 'completionOrder'),
            compensationResults: Rows::map($data, 'compensationResults'),
            compensationErrors: Rows::stringMap($data, 'compensationErrors'),
            stepErrors: Rows::stringMap($data, 'stepErrors'),
            tryResults: Rows::map($data, 'tryResults'),
            currentPhase: $phase === null ? null : TccPhase::tryFrom($phase),
            idempotencyKeys: Rows::strings($data, 'idempotencyKeys'),
            topologyLayers: $layers,
            failureReason: Rows::nullableString($data, 'failureReason'),
            completedAt: $completedAt === null ? null : self::parseInstant($completedAt),
            wakeAt: $wakeAt === null ? null : self::parseInstant($wakeAt),
            searchAttributes: $attributes,
            signals: Rows::map($data, 'signals'),
            timers: Rows::stringMap($data, 'timers'),
            waitingOn: Rows::nullableString($data, 'waitingOn'),
            parentCorrelationId: Rows::nullableString($data, 'parentCorrelationId'),
            children: Rows::stringMap($data, 'children'),
            report: Rows::nullableMap($data, 'report'),
        );
    }

    public static function instant(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
    }

    public static function parseInstant(string $value): DateTimeImmutable
    {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * The one string form a search attribute is compared in, by every provider: a database column holds a
     * string, so the in-memory and cache providers compare the same string rather than PHP's loose ==.
     */
    public static function attributeValue(string|int|float|bool $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }
}

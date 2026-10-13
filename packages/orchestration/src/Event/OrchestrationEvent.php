<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Event;

use DateTimeImmutable;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Persistence\ExecutionState;
use Firefly\Orchestration\Persistence\StateSerializer;
use Throwable;

/**
 * A lifecycle event as it leaves the process (jafly OrchestrationEvent). `topic`, `type` and `key` are set by
 * #[StepEvent], #[TccEvent] and outputEventType; a lifecycle event leaves them null and the EDA publisher
 * derives them. The payload is normalized, so it serializes on every EDA transport.
 */
final readonly class OrchestrationEvent
{
    public const string EXECUTION_STARTED = 'EXECUTION_STARTED';

    public const string EXECUTION_COMPLETED = 'EXECUTION_COMPLETED';

    public const string STEP_COMPLETED = 'STEP_COMPLETED';

    public const string STEP_FAILED = 'STEP_FAILED';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $eventType,
        public string $executionName,
        public string $correlationId,
        public ExecutionPattern $pattern,
        public ?string $stepId,
        public ?ExecutionStatus $status,
        public array $payload,
        public DateTimeImmutable $timestamp,
        public ?string $topic = null,
        public ?string $type = null,
        public ?string $key = null,
    ) {}

    public static function executionStarted(string $name, string $correlationId, ExecutionPattern $pattern, DateTimeImmutable $now): self
    {
        return new self(self::EXECUTION_STARTED, $name, $correlationId, $pattern, null, ExecutionStatus::Running, [], $now);
    }

    public static function executionCompleted(string $name, string $correlationId, ExecutionPattern $pattern, ExecutionStatus $status, DateTimeImmutable $now): self
    {
        return new self(self::EXECUTION_COMPLETED, $name, $correlationId, $pattern, null, $status, [], $now);
    }

    public static function stepCompleted(string $name, string $correlationId, ExecutionPattern $pattern, string $stepId, mixed $result, DateTimeImmutable $now): self
    {
        return new self(self::STEP_COMPLETED, $name, $correlationId, $pattern, $stepId, null, $result === null ? [] : ['result' => StateSerializer::normalize($result, "step {$stepId} result")], $now);
    }

    public static function stepFailed(string $name, string $correlationId, ExecutionPattern $pattern, string $stepId, Throwable $error, DateTimeImmutable $now): self
    {
        return new self(self::STEP_FAILED, $name, $correlationId, $pattern, $stepId, null, ['error' => $error->getMessage(), 'errorType' => $error::class], $now);
    }

    public function withTopic(string $topic): self
    {
        return new self($this->eventType, $this->executionName, $this->correlationId, $this->pattern, $this->stepId, $this->status, $this->payload, $this->timestamp, $topic === '' ? null : $topic, $this->type, $this->key);
    }

    public function withType(string $type): self
    {
        return new self($this->eventType, $this->executionName, $this->correlationId, $this->pattern, $this->stepId, $this->status, $this->payload, $this->timestamp, $this->topic, $type === '' ? null : $type, $this->key);
    }

    public function withKey(string $key): self
    {
        return new self($this->eventType, $this->executionName, $this->correlationId, $this->pattern, $this->stepId, $this->status, $this->payload, $this->timestamp, $this->topic, $this->type, $key === '' ? null : $key);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'eventType' => $this->eventType,
            'executionName' => $this->executionName,
            'correlationId' => $this->correlationId,
            'pattern' => $this->pattern->value,
            'stepId' => $this->stepId,
            'status' => $this->status?->value,
            'payload' => $this->payload,
            'timestamp' => ExecutionState::instant($this->timestamp),
            'key' => $this->key,
        ];
    }
}

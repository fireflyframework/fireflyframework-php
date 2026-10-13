<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Dlq;

use DateTimeImmutable;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\Rows;
use Firefly\Orchestration\Persistence\ExecutionState;
use Firefly\Orchestration\Persistence\StateSerializer;
use Illuminate\Support\Str;
use Throwable;

/**
 * One failed execution step, kept for an operator (jafly DeadLetterEntry). It carries the execution's input
 * and headers so DeadLetterRetrier can start the same definition again (spec decision D13); a retried
 * execution carries RETRY_OF/RETRY_COUNT headers, and if it fails too, its own entry counts one more retry.
 */
final readonly class DeadLetterEntry
{
    public const string RETRY_OF_HEADER = 'x-firefly-dlq-retry-of';

    public const string RETRY_COUNT_HEADER = 'x-firefly-dlq-retry-count';

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $id,
        public string $executionName,
        public string $correlationId,
        public ExecutionPattern $pattern,
        public ?string $stepId,
        public ExecutionStatus $statusAtFailure,
        public string $errorMessage,
        public string $errorType,
        public array $input,
        public array $headers,
        public int $retryCount,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $lastRetriedAt = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $headers
     */
    public static function create(string $executionName, string $correlationId, ExecutionPattern $pattern, ?string $stepId, ExecutionStatus $statusAtFailure, Throwable $error, array $input, array $headers, DateTimeImmutable $now, int $retryCount = 0): self
    {
        /** @var array<string, mixed> $normalized */
        $normalized = StateSerializer::normalize($input, 'input');

        return new self(Str::uuid()->toString(), $executionName, $correlationId, $pattern, $stepId, $statusAtFailure, $error->getMessage(), $error::class, $normalized, $headers, $retryCount, $now);
    }

    public function withRetry(DateTimeImmutable $now): self
    {
        return new self($this->id, $this->executionName, $this->correlationId, $this->pattern, $this->stepId, $this->statusAtFailure, $this->errorMessage, $this->errorType, $this->input, $this->headers, $this->retryCount + 1, $this->createdAt, $now);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'executionName' => $this->executionName,
            'correlationId' => $this->correlationId,
            'pattern' => $this->pattern->value,
            'stepId' => $this->stepId,
            'statusAtFailure' => $this->statusAtFailure->value,
            'errorMessage' => $this->errorMessage,
            'errorType' => $this->errorType,
            'input' => $this->input,
            'headers' => $this->headers,
            'retryCount' => $this->retryCount,
            'createdAt' => ExecutionState::instant($this->createdAt),
            'lastRetriedAt' => $this->lastRetriedAt === null ? null : ExecutionState::instant($this->lastRetriedAt),
        ];
    }

    /** @param array<array-key, mixed> $row */
    public static function fromArray(array $row): self
    {
        $retried = Rows::nullableString($row, 'lastRetriedAt');

        return new self(
            id: Rows::string($row, 'id'),
            executionName: Rows::string($row, 'executionName'),
            correlationId: Rows::string($row, 'correlationId'),
            pattern: ExecutionPattern::tryFrom(Rows::string($row, 'pattern')) ?? ExecutionPattern::Saga,
            stepId: Rows::nullableString($row, 'stepId'),
            statusAtFailure: ExecutionStatus::tryFrom(Rows::string($row, 'statusAtFailure')) ?? ExecutionStatus::Failed,
            errorMessage: Rows::string($row, 'errorMessage'),
            errorType: Rows::string($row, 'errorType'),
            input: Rows::map($row, 'input'),
            headers: Rows::stringMap($row, 'headers'),
            retryCount: Rows::int($row, 'retryCount'),
            createdAt: ExecutionState::parseInstant(Rows::string($row, 'createdAt', 'now')),
            lastRetriedAt: $retried === null ? null : ExecutionState::parseInstant($retried),
        );
    }
}

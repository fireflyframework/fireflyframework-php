<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Composition;

use Firefly\Orchestration\Saga\Engine\SagaResult;
use Throwable;

/**
 * What a composition did: each member's SagaResult by alias, what was compensated and what was skipped.
 *
 * `compensated` lists only the members whose every completed, compensable step was undone. A member the
 * rollback could not undo — compensate() refused it, or one of its compensations failed — is listed in
 * `compensationFailures` instead, with why, so a caller can tell a clean rollback from a partial one.
 * `optionalFailures` holds the optional members that failed or could not start, which the composition
 * tolerated.
 */
final readonly class CompositionResult
{
    /**
     * @param  array<string, SagaResult>  $sagaResults
     * @param  list<string>  $compensated
     * @param  list<string>  $skipped
     * @param  array<string, Throwable>  $compensationFailures  alias => why that member is not fully compensated
     * @param  array<string, Throwable>  $optionalFailures  alias => why that optional member failed or did not start
     */
    public function __construct(
        public string $name,
        public string $correlationId,
        public bool $success,
        public array $sagaResults,
        public ?Throwable $error = null,
        public array $compensated = [],
        public array $skipped = [],
        public array $compensationFailures = [],
        public array $optionalFailures = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $messages = static fn (Throwable $error): string => $error->getMessage();

        return [
            'name' => $this->name,
            'correlationId' => $this->correlationId,
            'success' => $this->success,
            'error' => $this->error?->getMessage(),
            'compensated' => $this->compensated,
            'compensationFailures' => array_map($messages, $this->compensationFailures),
            'skipped' => $this->skipped,
            'optionalFailures' => array_map($messages, $this->optionalFailures),
            'sagas' => array_map(static fn (SagaResult $result): array => $result->toArray(), $this->sagaResults),
        ];
    }
}

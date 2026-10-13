<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Composition;

use Firefly\Orchestration\Saga\Engine\SagaResult;
use Throwable;

/** What a composition did: each member's SagaResult by alias, what was compensated and what was skipped. */
final readonly class CompositionResult
{
    /**
     * @param  array<string, SagaResult>  $sagaResults
     * @param  list<string>  $compensated
     * @param  list<string>  $skipped
     */
    public function __construct(
        public string $name,
        public string $correlationId,
        public bool $success,
        public array $sagaResults,
        public ?Throwable $error = null,
        public array $compensated = [],
        public array $skipped = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'correlationId' => $this->correlationId,
            'success' => $this->success,
            'error' => $this->error?->getMessage(),
            'compensated' => $this->compensated,
            'skipped' => $this->skipped,
            'sagas' => array_map(static fn (SagaResult $result): array => $result->toArray(), $this->sagaResults),
        ];
    }
}

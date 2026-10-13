<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tcc\Composition;

use Firefly\Orchestration\Tcc\Engine\TccResult;
use Throwable;

/**
 * What a TCC composition did, member by member — the CompositionResult shape for TCC members.
 *
 * `compensated` lists only the members whose cancel ran clean. A member the rollback could not undo —
 * compensate() refused it, or one of its participants' cancels failed — is listed in `compensationFailures`
 * instead, with why, so a caller can tell a clean rollback from a partial one. `optionalFailures` holds the
 * optional members that did not confirm or could not start, which the composition tolerated.
 */
final readonly class TccCompositionResult
{
    /**
     * @param  array<string, TccResult>  $results
     * @param  list<string>  $compensated
     * @param  list<string>  $skipped
     * @param  array<string, Throwable>  $compensationFailures  alias => why that member is not fully cancelled
     * @param  array<string, Throwable>  $optionalFailures  alias => why that optional member did not confirm or start
     */
    public function __construct(
        public string $name,
        public string $correlationId,
        public bool $success,
        public array $results,
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
            'tccs' => array_map(static fn (TccResult $result): array => $result->toArray(), $this->results),
        ];
    }
}

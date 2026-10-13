<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Recovery;

/** What one recovery sweep found and did; `errors` maps a correlation id to why its recovery failed. */
final readonly class RecoveryReport
{
    /**
     * @param  list<string>  $stale
     * @param  list<string>  $resumed
     * @param  list<string>  $compensated
     * @param  array<string, string>  $errors
     */
    public function __construct(
        public array $stale = [],
        public array $resumed = [],
        public array $compensated = [],
        public array $errors = [],
        public int $cleaned = 0,
    ) {}

    /** @return array{stale: list<string>, resumed: list<string>, compensated: list<string>, errors: array<string, string>, cleaned: int} */
    public function toArray(): array
    {
        return ['stale' => $this->stale, 'resumed' => $this->resumed, 'compensated' => $this->compensated, 'errors' => $this->errors, 'cleaned' => $this->cleaned];
    }
}

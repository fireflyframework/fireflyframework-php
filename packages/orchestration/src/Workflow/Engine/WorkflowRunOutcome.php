<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Engine;

use Throwable;

/**
 * Where one WorkflowExecutor pass stopped: the workflow completed, waits on a step, failed (`timedOut` when the
 * workflow's own deadline passed), needs a child started for a step, or continues as new.
 */
final readonly class WorkflowRunOutcome
{
    public const string COMPLETED = 'completed';

    public const string WAITING = 'waiting';

    public const string FAILED = 'failed';

    public const string CHILD = 'child';

    public const string CONTINUE = 'continue';

    /**
     * @param  array<string, mixed>  $childInput
     */
    private function __construct(
        public string $kind,
        public ?string $stepId = null,
        public ?Throwable $error = null,
        public array $childInput = [],
        public ?ContinueAsNew $continueAsNew = null,
        public bool $timedOut = false,
    ) {}

    public static function completed(): self
    {
        return new self(self::COMPLETED);
    }

    public static function waiting(string $stepId): self
    {
        return new self(self::WAITING, $stepId);
    }

    public static function failed(string $stepId, Throwable $error, bool $timedOut = false): self
    {
        return new self(self::FAILED, $stepId, $error, timedOut: $timedOut);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function child(string $stepId, array $input): self
    {
        return new self(self::CHILD, $stepId, childInput: $input);
    }

    public static function continueAsNew(string $stepId, ContinueAsNew $next): self
    {
        return new self(self::CONTINUE, $stepId, continueAsNew: $next);
    }
}

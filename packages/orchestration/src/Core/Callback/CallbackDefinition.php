<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core\Callback;

use Closure;
use Firefly\Orchestration\Core\Rows;
use LogicException;
use Throwable;

/**
 * One #[OnSagaComplete] / #[OnSagaError] / #[OnTccComplete] / #[OnTccError] / #[OnStepComplete] /
 * #[OnWorkflowComplete] / #[OnWorkflowError] method, or a builder closure. `parameters` is the compiled kind of
 * each parameter, by position: the context, the error, or the result (a SagaResult, TccResult, ExecutionState
 * or a step's result). `async` (spec decision D11) orders the callback after the synchronous ones; PHP has no
 * fire-and-forget thread to hand it to.
 */
final readonly class CallbackDefinition
{
    public const string CONTEXT = 'context';

    public const string ERROR = 'error';

    public const string RESULT = 'result';

    /**
     * @param  list<string>  $parameters
     * @param  list<string>  $errorTypes  class names; empty matches every error
     * @param  list<string>  $stepIds  step ids; empty matches every step
     */
    public function __construct(
        public ?string $class = null,
        public ?string $method = null,
        public array $parameters = [],
        public bool $async = false,
        public int $priority = 0,
        public array $errorTypes = [],
        public bool $suppressError = false,
        public array $stepIds = [],
        public ?Closure $closure = null,
    ) {}

    /**
     * @param  list<string>  $errorTypes
     * @param  list<string>  $stepIds
     */
    public static function closure(Closure $callback, bool $async = false, int $priority = 0, array $errorTypes = [], bool $suppressError = false, array $stepIds = []): self
    {
        return new self(async: $async, priority: $priority, errorTypes: $errorTypes, suppressError: $suppressError, stepIds: $stepIds, closure: $callback);
    }

    public function matchesError(?Throwable $error): bool
    {
        if ($this->errorTypes === [] || $error === null) {
            return true;
        }

        foreach ($this->errorTypes as $type) {
            if (is_a($error, $type)) {
                return true;
            }
        }

        return false;
    }

    public function matchesStep(?string $stepId): bool
    {
        return $this->stepIds === [] || $stepId === null || in_array($stepId, $this->stepIds, true);
    }

    public function describe(): string
    {
        return $this->closure !== null ? 'closure' : $this->class.'::'.$this->method;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        if ($this->closure !== null) {
            throw new LogicException('A closure callback cannot be compiled into a manifest.');
        }

        return [
            'class' => $this->class, 'method' => $this->method, 'parameters' => $this->parameters, 'async' => $this->async,
            'priority' => $this->priority, 'errorTypes' => $this->errorTypes, 'suppressError' => $this->suppressError,
            'stepIds' => $this->stepIds,
        ];
    }

    /** @param array<array-key, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            Rows::nullableString($row, 'class'),
            Rows::nullableString($row, 'method'),
            Rows::strings($row, 'parameters'),
            Rows::bool($row, 'async'),
            Rows::int($row, 'priority'),
            Rows::strings($row, 'errorTypes'),
            Rows::bool($row, 'suppressError'),
            Rows::strings($row, 'stepIds'),
        );
    }
}

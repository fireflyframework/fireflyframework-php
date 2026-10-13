<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tcc\Builder;

use Closure;
use Firefly\Orchestration\Core\Callback\CallbackDefinition;
use Firefly\Orchestration\Core\ExecutionContext;
use Firefly\Orchestration\Tcc\Registry\TccDefinition;
use Firefly\Orchestration\Tcc\Registry\TccParticipantDefinition;

/**
 * The programmatic TCC DSL (jafly TccBuilder). Phase closures receive `(mixed $implicit, ExecutionContext $ctx)`:
 * the participant input for try, the try result for confirm and cancel.
 */
final class TccBuilder
{
    /** @var array<string, TccParticipantDefinition> */
    private array $participants = [];

    private int $timeoutMs = -1;

    private bool $retryEnabled = true;

    private int $maxRetries = 3;

    private int $backoffMs = 1000;

    private string $triggerEventType = '';

    /** @var list<CallbackDefinition> */
    private array $onComplete = [];

    /** @var list<CallbackDefinition> */
    private array $onError = [];

    private function __construct(private readonly string $name) {}

    public static function tcc(string $name): self
    {
        return new self($name);
    }

    public function timeoutMs(int $milliseconds): self
    {
        $this->timeoutMs = $milliseconds;

        return $this;
    }

    public function retry(bool $enabled, int $maxRetries = 3, int $backoffMs = 1000): self
    {
        $this->retryEnabled = $enabled;
        $this->maxRetries = $maxRetries;
        $this->backoffMs = $backoffMs;

        return $this;
    }

    public function triggerEventType(string $eventType): self
    {
        $this->triggerEventType = $eventType;

        return $this;
    }

    public function participant(string $id): TccParticipantBuilder
    {
        return new TccParticipantBuilder($this, $this->name, $id);
    }

    /**
     * @param  Closure(ExecutionContext, mixed): void  $callback
     */
    public function onComplete(Closure $callback, int $priority = 0, bool $async = false): self
    {
        $this->onComplete[] = CallbackDefinition::closure($callback, $async, $priority);

        return $this;
    }

    /**
     * @param  Closure(ExecutionContext, mixed): void  $callback
     * @param  list<string>  $errorTypes
     */
    public function onError(Closure $callback, bool $suppressError = false, array $errorTypes = [], int $priority = 0, bool $async = false): self
    {
        $this->onError[] = CallbackDefinition::closure($callback, $async, $priority, $errorTypes, $suppressError);

        return $this;
    }

    /** @internal called by TccParticipantBuilder::add() */
    public function addParticipant(TccParticipantDefinition $participant): self
    {
        $this->participants[$participant->id] = $participant;

        return $this;
    }

    public function build(): TccDefinition
    {
        return new TccDefinition($this->name, $this->participants, $this->timeoutMs, $this->retryEnabled, $this->maxRetries, $this->backoffMs, $this->triggerEventType, $this->onComplete, $this->onError);
    }
}

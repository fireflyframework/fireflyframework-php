<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tcc\Builder;

use Closure;
use Firefly\Orchestration\Core\ClosureInvocation;
use Firefly\Orchestration\Core\Exception\TopologyValidationException;
use Firefly\Orchestration\Saga\Registry\StepEventDefinition;
use Firefly\Orchestration\Tcc\Registry\TccParticipantDefinition;
use Firefly\Orchestration\Tcc\Registry\TccPhaseDefinition;

/** One participant of a TccBuilder; all three phases are required. */
final class TccParticipantBuilder
{
    private int $order = 0;

    private int $timeoutMs = -1;

    private bool $optional = false;

    private bool $jitter = false;

    private float $jitterFactor = 0.0;

    private ?StepEventDefinition $event = null;

    /** @var array<string, TccPhaseDefinition> */
    private array $phases = [];

    public function __construct(
        private readonly TccBuilder $tcc,
        private readonly string $tccName,
        private readonly string $id,
    ) {}

    public function order(int $order): self
    {
        $this->order = $order;

        return $this;
    }

    public function timeoutMs(int $milliseconds): self
    {
        $this->timeoutMs = $milliseconds;

        return $this;
    }

    public function optional(bool $optional = true): self
    {
        $this->optional = $optional;

        return $this;
    }

    public function jitter(bool $enabled = true, float $factor = 0.5): self
    {
        $this->jitter = $enabled;
        $this->jitterFactor = $factor;

        return $this;
    }

    public function tryWith(Closure $phase, int $timeoutMs = -1, int $retry = -1, int $backoffMs = -1): self
    {
        return $this->phase('try', $phase, $timeoutMs, $retry, $backoffMs);
    }

    public function confirmWith(Closure $phase, int $timeoutMs = -1, int $retry = -1, int $backoffMs = -1): self
    {
        return $this->phase('confirm', $phase, $timeoutMs, $retry, $backoffMs);
    }

    public function cancelWith(Closure $phase, int $timeoutMs = -1, int $retry = -1, int $backoffMs = -1): self
    {
        return $this->phase('cancel', $phase, $timeoutMs, $retry, $backoffMs);
    }

    public function event(string $topic, string $eventType, string $key = ''): self
    {
        $this->event = new StepEventDefinition($topic, $eventType, $key);

        return $this;
    }

    public function add(): TccBuilder
    {
        if (! isset($this->phases['try'], $this->phases['confirm'], $this->phases['cancel'])) {
            throw new TopologyValidationException("TCC [{$this->tccName}] participant [{$this->id}] needs try, confirm and cancel; it declares ".($this->phases === [] ? 'none' : implode(', ', array_keys($this->phases))).'.');
        }

        return $this->tcc->addParticipant(new TccParticipantDefinition(
            $this->id, $this->order, $this->phases['try'], $this->phases['confirm'], $this->phases['cancel'],
            $this->timeoutMs, $this->optional, $this->jitter, $this->jitterFactor, $this->event,
        ));
    }

    private function phase(string $name, Closure $phase, int $timeoutMs, int $retry, int $backoffMs): self
    {
        $this->phases[$name] = new TccPhaseDefinition(new ClosureInvocation($phase, "{$this->tccName}.{$this->id}:{$name}"), $timeoutMs, $retry, $backoffMs);

        return $this;
    }
}

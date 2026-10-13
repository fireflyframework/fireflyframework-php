<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Registry;

use Firefly\Orchestration\Core\Exception\TopologyValidationException;
use Firefly\Orchestration\Core\Rows;

/**
 * What a step waits for before it runs: one signal, one timer, all of a set, or any of a set. The value object
 * itself accepts anything so a compiled manifest and the builder share one gate: WorkflowDefinition calls
 * validate() for every step, which refuses an unknown kind, a SIGNAL or TIMER wait without exactly its one
 * member, an empty ALL or ANY set (an empty ANY suspends forever and an empty ALL resolves at once), a negative
 * delay or timeout, a blank or duplicate signal name or timer id.
 */
final readonly class WaitDefinition
{
    public const string SIGNAL = 'signal';

    public const string TIMER = 'timer';

    public const string ALL = 'all';

    public const string ANY = 'any';

    /**
     * @param  list<SignalWait>  $signals
     * @param  list<TimerWait>  $timers
     */
    public function __construct(
        public string $kind,
        public array $signals = [],
        public array $timers = [],
    ) {}

    /** @return list<string> */
    public static function kinds(): array
    {
        return [self::SIGNAL, self::TIMER, self::ALL, self::ANY];
    }

    /**
     * Refuses a wait the engine could not honor. `$where` names the owner, as in "Workflow [w] step [s]".
     *
     * @throws TopologyValidationException
     */
    public function validate(string $where): void
    {
        if (! in_array($this->kind, self::kinds(), true)) {
            throw new TopologyValidationException("{$where} waits with an unknown kind [{$this->kind}]; expected one of [".implode(', ', self::kinds()).'].');
        }

        $signals = count($this->signals);
        $timers = count($this->timers);
        if ($this->kind === self::SIGNAL && ($signals !== 1 || $timers !== 0)) {
            throw new TopologyValidationException("{$where} has a signal wait that must name exactly one signal and no timers (it has {$signals} signals and {$timers} timers).");
        }
        if ($this->kind === self::TIMER && ($timers !== 1 || $signals !== 0)) {
            throw new TopologyValidationException("{$where} has a timer wait that must name exactly one timer and no signals (it has {$timers} timers and {$signals} signals).");
        }
        if (($this->kind === self::ALL || $this->kind === self::ANY) && $signals + $timers === 0) {
            throw new TopologyValidationException("{$where} has a wait for {$this->kind} with no signals and no timers.");
        }

        $names = [];
        foreach ($this->signals as $signal) {
            if ($signal->name === '') {
                throw new TopologyValidationException("{$where} waits for a signal with a blank name.");
            }
            if ($signal->timeoutMs < 0) {
                throw new TopologyValidationException("{$where} waits for signal [{$signal->name}] with a negative timeout ({$signal->timeoutMs} ms).");
            }
            if (isset($names[$signal->name])) {
                throw new TopologyValidationException("{$where} waits for signal [{$signal->name}] twice.");
            }
            $names[$signal->name] = true;
        }

        $ids = [];
        foreach ($this->timers as $timer) {
            if ($timer->id === '') {
                throw new TopologyValidationException("{$where} waits for a timer with a blank id.");
            }
            if ($timer->delayMs < 0) {
                throw new TopologyValidationException("{$where} waits for timer [{$timer->id}] with a negative delay ({$timer->delayMs} ms).");
            }
            if (isset($ids[$timer->id])) {
                throw new TopologyValidationException("{$where} waits for timer [{$timer->id}] twice.");
            }
            $ids[$timer->id] = true;
        }
    }

    public static function signal(string $name, int $timeoutMs = 0): self
    {
        return new self(self::SIGNAL, [new SignalWait($name, $timeoutMs)]);
    }

    public static function timer(int $delayMs, string $id): self
    {
        return new self(self::TIMER, [], [new TimerWait($id, $delayMs)]);
    }

    /**
     * @param  list<SignalWait>  $signals
     * @param  list<TimerWait>  $timers
     */
    public static function all(array $signals, array $timers = []): self
    {
        return new self(self::ALL, $signals, $timers);
    }

    /**
     * @param  list<SignalWait>  $signals
     * @param  list<TimerWait>  $timers
     */
    public static function any(array $signals, array $timers = []): self
    {
        return new self(self::ANY, $signals, $timers);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'signals' => array_map(static fn (SignalWait $signal): array => ['name' => $signal->name, 'timeoutMs' => $signal->timeoutMs], $this->signals),
            'timers' => array_map(static fn (TimerWait $timer): array => ['id' => $timer->id, 'delayMs' => $timer->delayMs], $this->timers),
        ];
    }

    /**
     * Rebuilds a wait from its array form. The kind has no default: a manifest row with a missing or unknown kind
     * is refused here rather than silently read as a signal wait.
     *
     * @param  array<array-key, mixed>  $row
     *
     * @throws TopologyValidationException
     */
    public static function fromArray(array $row): self
    {
        $kind = Rows::string($row, 'kind');
        if (! in_array($kind, self::kinds(), true)) {
            throw new TopologyValidationException('A workflow wait has '.($kind === '' ? 'no kind' : "an unknown kind [{$kind}]").'; expected one of ['.implode(', ', self::kinds()).'].');
        }

        return new self(
            $kind,
            array_map(static fn (array $signal): SignalWait => new SignalWait(Rows::string($signal, 'name'), Rows::int($signal, 'timeoutMs')), Rows::rows($row, 'signals')),
            array_map(static fn (array $timer): TimerWait => new TimerWait(Rows::string($timer, 'id'), Rows::int($timer, 'delayMs')), Rows::rows($row, 'timers')),
        );
    }
}

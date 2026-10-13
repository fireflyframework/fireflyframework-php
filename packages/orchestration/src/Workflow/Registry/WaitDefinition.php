<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Registry;

use Firefly\Orchestration\Core\Rows;

/** What a step waits for before it runs: one signal, one timer, all of a set, or any of a set. */
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

    /** @param array<array-key, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            Rows::string($row, 'kind', self::SIGNAL),
            array_map(static fn (array $signal): SignalWait => new SignalWait(Rows::string($signal, 'name'), Rows::int($signal, 'timeoutMs')), Rows::rows($row, 'signals')),
            array_map(static fn (array $timer): TimerWait => new TimerWait(Rows::string($timer, 'id'), Rows::int($timer, 'delayMs')), Rows::rows($row, 'timers')),
        );
    }
}

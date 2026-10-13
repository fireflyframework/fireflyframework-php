<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tcc\Registry;

use Firefly\Orchestration\Core\Callback\CallbackDefinition;
use Firefly\Orchestration\Core\Exception\DefinitionNotFoundException;
use Firefly\Orchestration\Core\Exception\TopologyValidationException;
use Firefly\Orchestration\Core\Rows;

/** A TCC transaction (jafly TccDefinition); at least one participant, refused at construction otherwise. */
final readonly class TccDefinition
{
    /**
     * @param  array<string, TccParticipantDefinition>  $participants
     * @param  list<CallbackDefinition>  $onComplete
     * @param  list<CallbackDefinition>  $onError
     */
    public function __construct(
        public string $name,
        public array $participants,
        public int $timeoutMs = -1,
        public bool $retryEnabled = true,
        public int $maxRetries = 3,
        public int $backoffMs = 1000,
        public string $triggerEventType = '',
        public array $onComplete = [],
        public array $onError = [],
    ) {
        if ($participants === []) {
            throw new TopologyValidationException("TCC [{$name}] declares no participants.");
        }
    }

    /** @return list<TccParticipantDefinition> */
    public function ordered(): array
    {
        $participants = array_values($this->participants);
        usort($participants, static fn (TccParticipantDefinition $a, TccParticipantDefinition $b): int => $a->order <=> $b->order);

        return $participants;
    }

    public function participant(string $id): TccParticipantDefinition
    {
        return $this->participants[$id] ?? throw new DefinitionNotFoundException("TCC [{$this->name}] has no participant [{$id}].");
    }

    /** @return list<string> */
    public function participantIds(): array
    {
        return array_map(static fn (TccParticipantDefinition $participant): string => $participant->id, $this->ordered());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name, 'timeoutMs' => $this->timeoutMs, 'retryEnabled' => $this->retryEnabled,
            'maxRetries' => $this->maxRetries, 'backoffMs' => $this->backoffMs, 'triggerEventType' => $this->triggerEventType,
            'participants' => array_values(array_map(static fn (TccParticipantDefinition $participant): array => $participant->toArray(), $this->participants)),
            'onComplete' => array_map(static fn (CallbackDefinition $callback): array => $callback->toArray(), $this->onComplete),
            'onError' => array_map(static fn (CallbackDefinition $callback): array => $callback->toArray(), $this->onError),
        ];
    }

    /** @param array<array-key, mixed> $row */
    public static function fromArray(array $row): self
    {
        $participants = [];
        foreach (Rows::rows($row, 'participants') as $participantRow) {
            $participant = TccParticipantDefinition::fromArray($participantRow);
            $participants[$participant->id] = $participant;
        }

        return new self(
            name: Rows::string($row, 'name'),
            participants: $participants,
            timeoutMs: Rows::int($row, 'timeoutMs', -1),
            retryEnabled: Rows::bool($row, 'retryEnabled', true),
            maxRetries: Rows::int($row, 'maxRetries', 3),
            backoffMs: Rows::int($row, 'backoffMs', 1000),
            triggerEventType: Rows::string($row, 'triggerEventType'),
            onComplete: array_map(static fn (array $callback): CallbackDefinition => CallbackDefinition::fromArray($callback), Rows::rows($row, 'onComplete')),
            onError: array_map(static fn (array $callback): CallbackDefinition => CallbackDefinition::fromArray($callback), Rows::rows($row, 'onError')),
        );
    }
}

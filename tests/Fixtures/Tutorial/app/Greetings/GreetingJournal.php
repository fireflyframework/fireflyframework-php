<?php

declare(strict_types=1);

namespace App\Greetings;

use Firefly\EventSourcing\Aggregate\EventSourcedAggregateRoot;

final class GreetingJournal extends EventSourcedAggregateRoot
{
    private int $times = 0;

    private string $lastMessage = '';

    public static function aggregateType(): string
    {
        return 'greeting.journal';
    }

    public static function begin(string $name, string $message): self
    {
        $journal = new self($name);
        $journal->recordThat(new GreetingSaid($name, $message));

        return $journal;
    }

    public function say(string $message): void
    {
        $this->recordThat(new GreetingSaid($this->id(), $message));
    }

    public function times(): int
    {
        return $this->times;
    }

    public function lastMessage(): string
    {
        return $this->lastMessage;
    }

    protected function applyGreetingSaid(GreetingSaid $event): void
    {
        $this->times++;
        $this->lastMessage = $event->message;
    }
}

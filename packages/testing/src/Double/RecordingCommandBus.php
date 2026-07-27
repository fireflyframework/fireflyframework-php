<?php

declare(strict_types=1);

namespace Firefly\Testing\Double;

use Firefly\Cqrs\Command\CommandBus;

/** Records every command sent and returns a per-class canned result — the write-side bus Laravel can't fake. */
final class RecordingCommandBus implements CommandBus
{
    /** @var list<object> */
    public array $sent = [];

    /** @var array<string, mixed> */
    private array $results = [];

    public function willReturn(string $commandClass, mixed $result): self
    {
        $this->results[$commandClass] = $result;

        return $this;
    }

    public function send(object $command): mixed
    {
        $this->sent[] = $command;

        return $this->results[$command::class] ?? null;
    }

    /**
     * @return list<object>
     */
    public function handled(string $class): array
    {
        return array_values(array_filter($this->sent, static fn (object $c): bool => $c instanceof $class));
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\Support;

use Illuminate\Contracts\Bus\Dispatcher;

/** A bus that only records what it is asked to dispatch, so a test can read the job's connection and queue. */
final class RecordingBusDispatcher implements Dispatcher
{
    /** @var list<mixed> */
    public array $dispatched = [];

    public function dispatch($command): mixed
    {
        $this->dispatched[] = $command;

        return null;
    }

    public function dispatchSync($command, $handler = null): mixed
    {
        return null;
    }

    public function dispatchNow($command, $handler = null): mixed
    {
        return null;
    }

    public function dispatchAfterResponse($command, $handler = null): void {}

    /** @param  mixed  $jobs */
    public function chain($jobs = null): mixed
    {
        return null;
    }

    public function hasCommandHandler($command): bool
    {
        return false;
    }

    public function getCommandHandler($command): bool
    {
        return false;
    }

    /** @param  array<mixed>  $pipes */
    public function pipeThrough(array $pipes): static
    {
        return $this;
    }

    /** @param  array<mixed>  $map */
    public function map(array $map): static
    {
        return $this;
    }
}

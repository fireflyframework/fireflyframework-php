<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Event;

/**
 * How the DomainEventBridge reacts when EventPublisher::publish throws (the publish runs AFTER the DB commit, inside
 * DB::afterCommit — the write already succeeded). LOG (default): swallow + log; the command result stands, the
 * integration publish is best-effort. RAISE: re-throw wrapped in CommandProcessingException so the caller sees the
 * post-commit failure. Selected by firefly.cqrs.event_failure_strategy (pyfly EventFailureStrategy).
 */
enum EventFailureStrategy: string
{
    case Log = 'log';
    case Raise = 'raise';
}

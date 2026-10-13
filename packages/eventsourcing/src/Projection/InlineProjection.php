<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Projection;

/**
 * A projection its WRITER keeps current, in the same transaction as the events: the event-sourced orchestration read
 * model is one. The runner never advances it in catch-up or live mode, and the health component never counts its
 * lag. `php artisan firefly:eventsourcing:projections:rebuild <name>` is the one path that replays it, to repair a
 * read model or to build it the first time.
 */
interface InlineProjection extends Projection {}

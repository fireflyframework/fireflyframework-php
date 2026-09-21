<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Listeners;

use Firefly\Container\Attributes\Service;
use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\Data\Transaction\Attributes\Transactional;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Publishes NoteSaved INSIDE a #[Transactional] method (through the port, so the listeners registered on the
 * dispatcher hear it) and either returns or throws. saveWithNestedFailure() publishes for the outer title, then
 * lets the injected NestedNoteService publish for the inner one inside a savepoint that rolls back, and catches
 * that failure so the outer transaction commits. NOT final — the proxy extends it.
 */
#[Service]
#[Transactional]
class NoteService
{
    public function __construct(
        private readonly ApplicationEventPublisher $events,
        private readonly NestedNoteService $nested,
    ) {}

    public function save(string $title): void
    {
        DB::table('notes')->insert(['title' => $title]);
        $this->events->publish(new NoteSaved($title));
    }

    public function saveAndFail(string $title): void
    {
        DB::table('notes')->insert(['title' => $title]);
        $this->events->publish(new NoteSaved($title));

        throw new RuntimeException('failing after publish');
    }

    public function saveWithNestedFailure(string $outer, string $inner): void
    {
        DB::table('notes')->insert(['title' => $outer]);
        $this->events->publish(new NoteSaved($outer));

        try {
            $this->nested->saveAndFail($inner);
        } catch (RuntimeException) {
            // The savepoint is gone; the outer unit of work carries on to its commit.
        }
    }
}

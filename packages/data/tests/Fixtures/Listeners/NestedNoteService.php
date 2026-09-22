<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Listeners;

use Firefly\Container\Attributes\Service;
use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\Data\Transaction\Attributes\Transactional;
use Firefly\Data\Transaction\Propagation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The savepoint half of the capstone: a NESTED unit of work that inserts, publishes NoteSaved and throws, so the
 * savepoint rolls back while the transaction that NoteService::saveWithNestedFailure() opened goes on to commit.
 * A separate bean because a self-invocation would bypass the proxy (the documented Spring limitation); NOT final
 * — the proxy extends it.
 */
#[Service]
#[Transactional(propagation: Propagation::NESTED)]
class NestedNoteService
{
    public function __construct(private readonly ApplicationEventPublisher $events) {}

    public function saveAndFail(string $title): void
    {
        DB::table('notes')->insert(['title' => $title]);
        $this->events->publish(new NoteSaved($title));

        throw new RuntimeException('failing inside the savepoint');
    }
}

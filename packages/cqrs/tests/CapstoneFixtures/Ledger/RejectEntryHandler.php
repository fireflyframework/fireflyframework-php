<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CapstoneFixtures\Ledger;

use Firefly\Cqrs\Attributes\CommandHandler;
use Firefly\Data\Transaction\Attributes\Transactional;
use RuntimeException;

/** Writes, raises EntryPosted, then fails: the rollback must discard the event and evict nothing. */
#[CommandHandler]
class RejectEntryHandler
{
    public function __construct(private readonly EntryRepository $entries) {}

    #[Transactional]
    public function handle(RejectEntry $command): int
    {
        $entry = new Entry(['account' => $command->account, 'amount' => $command->amount]);
        $entry->post();
        $this->entries->save($entry);

        throw new RuntimeException('rejected after the write');
    }
}

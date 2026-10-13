<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CapstoneFixtures\Ledger;

use Firefly\Cqrs\Attributes\CommandHandler;
use Firefly\Data\Transaction\Attributes\Transactional;

#[CommandHandler]
class PostEntryHandler
{
    public function __construct(private readonly EntryRepository $entries) {}

    #[Transactional]
    public function handle(PostEntry $command): int
    {
        $entry = new Entry(['account' => $command->account, 'amount' => $command->amount]);
        $entry->post();
        $this->entries->save($entry);

        return (int) $entry->id;
    }
}

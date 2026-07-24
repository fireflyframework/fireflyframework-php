<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CapstoneFixtures\Banking;

use Firefly\Cqrs\Attributes\CommandHandler;
use Firefly\Data\Transaction\Attributes\Transactional;
use RuntimeException;

/**
 * NOT `final` — same reason as OpenAccountHandler: its #[Transactional] handle() is proxied by the generated
 * subclass, which would fatal ("cannot extend final class") if this class were final.
 */
#[CommandHandler]
class FailOpenAccountHandler
{
    public function __construct(private readonly AccountRepository $accounts) {}

    #[Transactional]
    public function handle(FailOpenAccount $command): void
    {
        $account = new Account(['owner' => $command->owner, 'balance' => $command->balance]);
        $account->open();
        $this->accounts->save($account);

        throw new RuntimeException('rollback'); // rolls back the whole unit of work -> no row, no in-process event
    }
}

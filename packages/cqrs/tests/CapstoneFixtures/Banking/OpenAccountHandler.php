<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CapstoneFixtures\Banking;

use Firefly\Cqrs\Attributes\CommandHandler;
use Firefly\Data\Transaction\Attributes\Transactional;

/**
 * NOT `final` — its #[Transactional] handle() means the M8 TransactionalBeanPostProcessor swaps this bean for a
 * generated proxy subclass (ProxyClassGenerator::generate() emits `final class ...Proxy extends \{Target}`), which
 * would fatal if the target class were itself final. Mirrors packages/data/tests/Fixtures/Ordering/PlaceOrderService
 * and Fixtures/Capstone/AccountService.
 */
#[CommandHandler]
class OpenAccountHandler
{
    public function __construct(private readonly AccountRepository $accounts) {}

    #[Transactional]
    public function handle(OpenAccount $command): int
    {
        $account = new Account(['owner' => $command->owner, 'balance' => $command->balance]);
        $account->open();
        $this->accounts->save($account);

        return $account->id;
    }
}

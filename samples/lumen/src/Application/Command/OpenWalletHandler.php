<?php

declare(strict_types=1);

namespace Lumen\Application\Command;

use Firefly\Cqrs\Attributes\CommandHandler;
use Firefly\Data\Transaction\Attributes\Transactional;
use Lumen\Domain\Wallet;
use Lumen\Infrastructure\WalletRepository;

/**
 * Handles OpenWallet: mints a new wallet id, opens the aggregate, and persists it. The handled command type is
 * inferred from the sole handle() parameter (HandlerScanner param inference) — no explicit #[CommandHandler(...)].
 *
 * #[Transactional] is LOAD-BEARING, not cosmetic: DefaultCommandBus opens no transaction of its own, and
 * EloquentRepository::save() only tracks the aggregate when transactionLevel() > 0. The generated transactional proxy
 * installs the TransactionTemplate that is the sole caller of DomainEventDispatcher::dispatchAfterCommit(), so without
 * this attribute the WalletOpened domain event would never publish and S5's ledger projector would never fire.
 *
 * Intentionally NOT final: the generated transactional proxy subclasses this handler (mirroring the framework's own
 * SignatureBag/TxWidget fixtures and the PlaceOrderService pattern in docs/modules/domain.md).
 */
#[CommandHandler]
class OpenWalletHandler
{
    public function __construct(private readonly WalletRepository $wallets) {}

    #[Transactional]
    public function handle(OpenWallet $command): string
    {
        $id = 'wlt-'.bin2hex(random_bytes(8));
        $wallet = Wallet::open($id, $command->ownerId, $command->currency);
        $this->wallets->save($wallet);

        return $id;
    }
}

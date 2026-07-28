<?php

declare(strict_types=1);

namespace Lumen\Application\Command;

/**
 * Command: move `amountMinor` (minor units) from `sourceWalletId` to `destinationWalletId` as a single atomic unit of
 * work. The handler debits the source and credits the destination inside one #[Transactional] boundary, so a failure
 * on either leg (e.g. a currency mismatch on the credit) rolls BOTH legs back — money can neither vanish nor double.
 */
final readonly class Transfer
{
    public function __construct(
        public string $sourceWalletId,
        public string $destinationWalletId,
        public int $amountMinor,
    ) {}
}

<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CapstoneFixtures\Ledger;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Data\Transaction\TransactionalManifest;

/** The transactional manifest the capstone compiles inline, bound the way firefly:cache's artifact would be. */
#[Configuration]
final class LedgerCqrsConfiguration
{
    public static function manifestPath(): string
    {
        return sys_get_temp_dir().'/firefly-cqrs-ledger-transactional.php';
    }

    #[Bean]
    public function transactionalManifest(): TransactionalManifest
    {
        return TransactionalManifest::load(self::manifestPath());
    }
}

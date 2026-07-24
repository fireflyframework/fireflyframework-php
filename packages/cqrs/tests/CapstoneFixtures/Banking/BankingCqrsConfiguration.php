<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CapstoneFixtures\Banking;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Data\Transaction\TransactionalManifest;

/**
 * The competing #[Bean] definition that overrides DataAutoConfiguration's empty default TransactionalManifest — the
 * ONLY real override seam (a plain $app->instance() would be silently overwritten at FlushDefinitions; see
 * packages/data/tests/Fixtures/Ordering/OrderingTransactionalConfiguration's docblock). Discovered by the same
 * BankingComponentsProvider that discovers the other Banking fixtures.
 */
#[Configuration]
final class BankingCqrsConfiguration
{
    public static function manifestPath(): string
    {
        return sys_get_temp_dir().'/firefly-cqrs-banking-transactional.php';
    }

    #[Bean]
    public function transactionalManifest(): TransactionalManifest
    {
        return TransactionalManifest::load(self::manifestPath());
    }
}

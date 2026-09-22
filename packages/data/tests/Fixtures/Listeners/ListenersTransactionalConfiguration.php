<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Listeners;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Data\Transaction\TransactionalManifest;

/**
 * Loads the compiled manifest for the Listeners fixtures as a competing bean DEFINITION — the real override
 * seam that makes DataAutoConfiguration's #[ConditionalOnMissingBean(TransactionalManifest::class)] default step
 * aside. See CapstoneTransactionalConfiguration for why a container instance binding would not work.
 */
#[Configuration]
final class ListenersTransactionalConfiguration
{
    public static function manifestPath(): string
    {
        return sys_get_temp_dir().'/firefly-data-listeners-transactional.php';
    }

    #[Bean]
    public function transactionalManifest(): TransactionalManifest
    {
        return TransactionalManifest::load(self::manifestPath());
    }
}

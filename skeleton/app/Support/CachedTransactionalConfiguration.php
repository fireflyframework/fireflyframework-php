<?php

declare(strict_types=1);

namespace App\Support;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Data\Transaction\TransactionalManifest;

/**
 * Category C: the app-side #[Configuration] that firefly:cache generates/ships. It LOADS the app's compiled
 * TransactionalManifest as a bean — the only override seam for it, because binding it directly on the Laravel
 * container is invisible to DataAutoConfiguration's #[ConditionalOnMissingBean] (which consults the
 * BeanDefinitionRegistry). Its default #[Order] 0 sorts strictly before DataAutoConfiguration's #[Order(1000)],
 * so the empty default steps aside. On a cached boot this #[Configuration] is discovered from the compiled
 * component/context manifests (never scanned); until firefly:cache has run, it returns an empty manifest.
 */
#[Configuration]
final class CachedTransactionalConfiguration
{
    #[Bean]
    public function transactionalManifest(): TransactionalManifest
    {
        $file = base_path('bootstrap/cache/firefly/transactional.php');

        return is_file($file) ? TransactionalManifest::load($file) : new TransactionalManifest([], []);
    }
}

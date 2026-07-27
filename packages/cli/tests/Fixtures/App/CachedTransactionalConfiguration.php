<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Fixtures\App;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Data\Transaction\TransactionalManifest;

/**
 * Category C: the app-side #[Configuration] that firefly:cache (M15) generates. It LOADS the app's compiled
 * TransactionalManifest as a bean — the ONLY override seam for it, because $app->instance(TransactionalManifest)
 * is invisible to DataAutoConfiguration's #[ConditionalOnMissingBean] (which consults the BeanDefinitionRegistry,
 * not the Laravel container). Its default #[Order] 0 sorts strictly before DataAutoConfiguration's #[Order(1000)],
 * so that empty default steps aside. On the cached boot this #[Configuration] is discovered from the compiled
 * component/context manifests (never scanned).
 *
 * The manifest path comes from FIREFLY_CACHE_DIR (set by the test before boot) because a test fixture has no fixed
 * base_path(); the skeleton's committed copy (Task 9) uses base_path('bootstrap/cache/firefly/transactional.php').
 */
#[Configuration]
final class CachedTransactionalConfiguration
{
    #[Bean]
    public function transactionalManifest(): TransactionalManifest
    {
        $path = getenv('FIREFLY_CACHE_DIR') ?: '';
        $file = $path.'/transactional.php';

        return $path !== '' && is_file($file)
            ? TransactionalManifest::load($file)
            : new TransactionalManifest([], []);
    }
}

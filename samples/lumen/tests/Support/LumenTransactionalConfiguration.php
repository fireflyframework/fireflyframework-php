<?php

declare(strict_types=1);

namespace Lumen\Tests\Support;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Data\Transaction\TransactionalManifest;

/**
 * The app-side #[Configuration] that a real firefly:cache boot generates for an application: it LOADS the compiled
 * TransactionalManifest as a #[Bean]. Registering this bean is the ONLY seam that makes DataAutoConfiguration's
 * #[ConditionalOnMissingBean(TransactionalManifest::class)] default step aside — a plain
 * $app->instance(TransactionalManifest::class, ...) is INVISIBLE to that condition (the ConditionEvaluator consults
 * the BeanDefinitionRegistry, not Laravel container bindings) and is overwritten by the empty default at
 * FlushDefinitions. Mirrors packages/data/tests/Fixtures/Capstone/CapstoneTransactionalConfiguration and the
 * skeleton's App\CachedTransactionalConfiguration.
 *
 * Discovered by the component scan LumenTestCase adds to firefly.scan.paths; LumenTestCase writes the compiled
 * manifest to manifestPath() in defineFireflyEnvironment BEFORE boot resolves this bean. #[Order(0)] sorts strictly
 * below DataAutoConfiguration's #[Order(1000)]. Once S4+ lands #[Transactional] handlers under Lumen\, the compiled
 * manifest is non-empty and every such handler is transparently proxied — "get the proxy wiring right once".
 */
#[Configuration]
final class LumenTransactionalConfiguration
{
    public static function manifestPath(): string
    {
        return sys_get_temp_dir().'/firefly-lumen-transactional.php';
    }

    #[Bean]
    #[Order(0)]
    public function transactionalManifest(): TransactionalManifest
    {
        $path = self::manifestPath();

        return is_file($path) ? TransactionalManifest::load($path) : new TransactionalManifest([], []);
    }
}

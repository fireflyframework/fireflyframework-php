<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Capstone;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Data\Transaction\TransactionalManifest;

/**
 * Simulates the app-side #[Configuration] that firefly:cache (M15) generates: it LOADS the app's compiled
 * TransactionalManifest as a bean. Registering this bean makes DataAutoConfiguration's
 * #[ConditionalOnMissingBean(TransactionalManifest::class)] default step aside — "the app's compiled manifest
 * overrides the empty default", exactly as that bean's own docblock promises.
 *
 * WHY THIS (and not $app->instance(TransactionalManifest::class, ...)): #[ConditionalOnMissingBean] is evaluated
 * by the ConditionEvaluator against the BeanDefinitionRegistry (containsType() — a definition's class, its
 * interfaces, or a #[Bean] return type), NOT against Laravel container bindings. A pre-boot $app->instance()
 * binding is therefore invisible to the condition (so the empty default never backs off) AND is overwritten by
 * the #[Bean] registration at FlushDefinitions. Only a competing bean DEFINITION — this #[Bean] — is the real
 * override seam. (Contrast firefly/scheduling, whose default is a bound()-guarded provider binding, where
 * $app->instance() DOES win — a different mechanism entirely.)
 *
 * It is discovered by the same FixtureComponentsProvider that discovers AccountService (both live under
 * Fixtures/Capstone), so it enters the boot as an auto-configuration definition; its #[Order] is the default 0,
 * strictly below DataAutoConfiguration's #[Order(1000)], so ConditionPassTwo evaluates it first and this manifest
 * is already in the registry when the default is checked.
 */
#[Configuration]
final class CapstoneTransactionalConfiguration
{
    public static function manifestPath(): string
    {
        return sys_get_temp_dir().'/firefly-data-capstone-transactional.php';
    }

    #[Bean]
    public function transactionalManifest(): TransactionalManifest
    {
        return TransactionalManifest::load(self::manifestPath());
    }
}

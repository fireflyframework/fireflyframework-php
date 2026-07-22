<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Ordering;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Data\Transaction\TransactionalManifest;

/**
 * DEVIATION FROM THE BRIEF (see MilestoneCapstoneTestCase's docblock and the T17 report): mirrors
 * Fixtures/Capstone/CapstoneTransactionalConfiguration exactly. A competing #[Bean] definition is the ONLY real
 * override seam for DataAutoConfiguration's #[ConditionalOnMissingBean(TransactionalManifest::class)] default —
 * that condition is evaluated against the BeanDefinitionRegistry (containsType()), NEVER against a raw Laravel
 * $app->instance() binding, which a later #[Bean] registration silently overwrites at FlushDefinitions anyway (see
 * CapstoneTransactionalConfiguration's docblock for the full explanation; empirically confirmed while implementing
 * this task — the brief's original $app->instance(TransactionalManifest::class, ...) left PlaceOrderService
 * unproxied). Discovered by the SAME OrderingComponentsProvider that discovers the other Ordering fixtures, so it
 * enters the boot as an auto-configuration definition; its #[Order] is the default 0, strictly below
 * DataAutoConfiguration's #[Order(1000)], so ConditionPassTwo evaluates it first and this manifest is already in
 * the registry when the default is checked.
 */
#[Configuration]
final class OrderingTransactionalConfiguration
{
    public static function manifestPath(): string
    {
        return sys_get_temp_dir().'/firefly-data-ordering-transactional.php';
    }

    #[Bean]
    public function transactionalManifest(): TransactionalManifest
    {
        return TransactionalManifest::load(self::manifestPath());
    }
}

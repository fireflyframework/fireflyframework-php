<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Chain;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Data\Transaction\TransactionInterceptor;
use Firefly\Data\Transaction\TransactionTemplate;

/**
 * Supplies the RecordingTransactionInterceptor as THE TransactionInterceptor bean of a boot that scans this
 * directory. A competing bean DEFINITION is the real override seam: DataAutoConfiguration's
 * #[ConditionalOnMissingBean(TransactionInterceptor::class)] default backs off because the ConditionEvaluator
 * consults the BeanDefinitionRegistry, and this #[Order]-0 configuration is evaluated before the #[Order(1000)]
 * default (see Fixtures/Capstone/CapstoneTransactionalConfiguration for the full account of why a pre-boot
 * $app->instance() would NOT do). The post-processor then injects this instance into every proxy's tx link.
 */
#[Configuration]
final class ChainTransactionConfiguration
{
    #[Bean]
    public function transactionInterceptor(TransactionTemplate $template): TransactionInterceptor
    {
        return new RecordingTransactionInterceptor($template);
    }
}

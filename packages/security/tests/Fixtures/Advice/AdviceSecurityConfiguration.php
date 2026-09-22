<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Fixtures\Advice;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Security\Access\PermissionEvaluator;

/**
 * Supplies the OwnerPermissionEvaluator as THE PermissionEvaluator bean of a boot that scans this directory —
 * the way a real application hands the framework its own `hasPermission()` semantics. A competing bean
 * DEFINITION is the real override seam: SecurityAutoConfiguration's #[ConditionalOnMissingBean(PermissionEvaluator)]
 * deny-all default backs off because the ConditionEvaluator consults the BeanDefinitionRegistry, and this
 * #[Order]-0 configuration is evaluated before the #[Order(500)] default (a pre-boot $app->instance() would be
 * invisible to the condition and overwritten at FlushDefinitions — see firefly/data's
 * Fixtures/Capstone/CapstoneTransactionalConfiguration for the full account).
 *
 * With it, the proxied filters can be seen to NARROW rather than blanket-deny: ada keeps her even-numbered
 * reports and loses bob's odd-numbered ones. It carries no security rule of its own, so it never enters the
 * proxy plan the sibling scan test pins as exactly [OwnedReportService, ReportService].
 */
#[Configuration]
final class AdviceSecurityConfiguration
{
    #[Bean]
    public function permissionEvaluator(): PermissionEvaluator
    {
        return new OwnerPermissionEvaluator;
    }
}

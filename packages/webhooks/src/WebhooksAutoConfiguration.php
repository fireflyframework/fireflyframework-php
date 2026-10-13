<?php

declare(strict_types=1);

namespace Firefly\Webhooks;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;

/**
 * The webhooks beans, every one behind firefly.webhooks.enabled (default false): installing the package is inert
 * until an application opts in. Beans are eager singletons, so a misconfigured provider refuses the boot here.
 * Each backs off #[ConditionalOnMissingBean], so an application can bring its own store, validators or processor.
 */
#[Configuration]
#[Order(1000)]
final class WebhooksAutoConfiguration
{
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.webhooks.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(WebhooksSettings::class)]
    public function webhooksSettings(Config $config): WebhooksSettings
    {
        return WebhooksSettings::fromConfig($config);
    }
}

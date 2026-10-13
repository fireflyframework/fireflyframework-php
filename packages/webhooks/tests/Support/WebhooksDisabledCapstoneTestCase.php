<?php

declare(strict_types=1);

namespace Firefly\Webhooks\Tests\Support;

/** firefly.webhooks.enabled=false: the default every application starts from. */
abstract class WebhooksDisabledCapstoneTestCase extends WebhooksCapstoneTestCase
{
    protected function webhooksEnabled(): bool
    {
        return false;
    }
}

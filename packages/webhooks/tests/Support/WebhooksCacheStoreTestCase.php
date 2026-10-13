<?php

declare(strict_types=1);

namespace Firefly\Webhooks\Tests\Support;

use Firefly\Testing\FireflyDatabaseTestCase;
use Firefly\Webhooks\WebhooksServiceProvider;
use Firefly\Webhooks\WebhooksWiringProvider;

/** Webhooks enabled with the idempotency store left at its default, over Testbench + sqlite :memory:. */
abstract class WebhooksCacheStoreTestCase extends FireflyDatabaseTestCase
{
    /** @return list<class-string> */
    protected function fireflyProviders(): array
    {
        return [WebhooksServiceProvider::class, WebhooksWiringProvider::class];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            'firefly.webhooks.enabled' => true,
            'firefly.webhooks.providers' => ['acme' => ['validator' => 'hmac', 'secret' => 'acme-secret']],
        ];
    }
}

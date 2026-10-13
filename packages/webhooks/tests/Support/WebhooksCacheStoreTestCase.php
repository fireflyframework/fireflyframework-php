<?php

declare(strict_types=1);

namespace Firefly\Webhooks\Tests\Support;

use Firefly\Eda\EdaServiceProvider;
use Firefly\Testing\FireflyDatabaseTestCase;
use Firefly\Webhooks\WebhooksServiceProvider;
use Firefly\Webhooks\WebhooksWiringProvider;

/** Webhooks enabled with the idempotency store left at its default, over Testbench + sqlite :memory: — with firefly/eda, whose EventPublisher the processor publishes to. */
abstract class WebhooksCacheStoreTestCase extends FireflyDatabaseTestCase
{
    /** @return list<class-string> */
    protected function fireflyProviders(): array
    {
        return [EdaServiceProvider::class, WebhooksServiceProvider::class, WebhooksWiringProvider::class];
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

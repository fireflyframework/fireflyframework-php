<?php

declare(strict_types=1);

namespace Lumen\Tests\Web;

use Firefly\Webhooks\WebhooksServiceProvider;
use Firefly\Webhooks\WebhooksWiringProvider;
use Illuminate\Support\ServiceProvider;
use Illuminate\Testing\TestResponse;
use Lumen\Tests\LumenTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The lumen boot harness with firefly/webhooks on and one provider, `card-processor`, verified by the default `hmac`
 * validator: `X-Signature: sha256=<hex HMAC-SHA256 of the body>` and `X-Idempotency-Key`. Accepted deliveries are
 * published to the sample's real InMemoryEventBus, where CardTopUpListener turns them into deposits. The idempotency
 * store is `memory` because one test is one process; a deployment uses `cache` or `database`.
 */
abstract class LumenWebhooksTestCase extends LumenTestCase
{
    public const string SECRET = 'lumen-card-secret';

    /** @return list<class-string<ServiceProvider>> */
    protected function fireflyProviders(): array
    {
        return [...parent::fireflyProviders(), WebhooksServiceProvider::class, WebhooksWiringProvider::class];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'firefly.webhooks.enabled' => true,
            'firefly.webhooks.idempotency.store' => 'memory',
            'firefly.webhooks.providers' => [
                'card-processor' => ['secret' => self::SECRET],
            ],
        ];
    }

    /**
     * POSTs a raw body with exact headers. A signature covers bytes, so postJson() — which re-encodes — cannot be used.
     *
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    public function postWebhook(string $uri, string $body, array $headers = []): TestResponse
    {
        return $this->call('POST', $uri, [], [], [], $this->transformHeadersToServerVars(['Content-Type' => 'application/json', ...$headers]), $body);
    }
}

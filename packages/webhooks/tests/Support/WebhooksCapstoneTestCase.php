<?php

declare(strict_types=1);

namespace Firefly\Webhooks\Tests\Support;

use Firefly\Eda\EventPublisher;
use Firefly\Testing\Double\RecordingEventPublisher;
use Firefly\Testing\FireflyTestCase;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Web\WebServiceProvider;
use Firefly\Webhooks\WebhooksServiceProvider;
use Firefly\Webhooks\WebhooksWiringProvider;
use Illuminate\Foundation\Application;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Webhooks over the REAL HTTP kernel: the web layer (problem+json), both webhooks providers, five providers of
 * every kind, the in-memory idempotency store (one test = one process) and a recording EventPublisher bound
 * before boot as the bus the processor publishes to.
 */
abstract class WebhooksCapstoneTestCase extends FireflyTestCase
{
    public RecordingEventPublisher $events;

    /** @return list<class-string> */
    protected function fireflyProviders(): array
    {
        return [ValidationServiceProvider::class, WebServiceProvider::class, WebhooksServiceProvider::class, WebhooksWiringProvider::class];
    }

    protected function webhooksEnabled(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            'firefly.webhooks.enabled' => $this->webhooksEnabled(),
            'firefly.webhooks.max-body-bytes' => 4096,
            'firefly.webhooks.idempotency.store' => 'memory',
            'firefly.webhooks.providers' => [
                'acme' => ['secret' => 'acme-secret'],
                'stripe' => ['validator' => 'stripe', 'secret' => 'whsec_test'],
                'github' => ['validator' => 'github', 'secret' => 'gh-secret'],
                'twilio' => ['validator' => 'twilio', 'secret' => 'twilio-token', 'event-type-field' => 'MessageStatus', 'idempotency-field' => 'MessageSid'],
                'open' => ['validator' => 'none'],
            ],
        ];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        $this->events = new RecordingEventPublisher;
        $app->instance(EventPublisher::class, $this->bus());
    }

    protected function bus(): EventPublisher
    {
        return $this->events;
    }

    /**
     * POSTs a raw body with exact headers — what a signature covers is the bytes, so postJson() (which re-encodes)
     * cannot be used.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $form
     * @return TestResponse<Response>
     */
    public function postWebhook(string $uri, string $body, array $headers = [], array $form = [], string $contentType = 'application/json'): TestResponse
    {
        return $this->call('POST', $uri, $form, [], [], $this->transformHeadersToServerVars(['Content-Type' => $contentType, ...$headers]), $body);
    }
}

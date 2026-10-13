<?php

declare(strict_types=1);

namespace Firefly\Callbacks\Delivery;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory;

/**
 * The CallbackSender over Laravel's HTTP client. The Factory is resolved on EVERY send, never held: Http::fake()
 * swaps the container binding, and a sender built at boot would keep posting to the real network from a test (the
 * QueueMessageBroker rule for the bus dispatcher). Redirects are not followed — a 3xx is the receiver's answer, and
 * following it would let a subscription be bounced to a host the allow-list never saw.
 */
final class LaravelHttpCallbackSender implements CallbackSender
{
    public function __construct(private readonly Container $container) {}

    public function send(string $url, string $body, array $headers, float $timeoutSeconds): int
    {
        /** @var Factory $http */
        $http = $this->container->make(Factory::class);

        return $http->withHeaders($headers)
            ->timeout($timeoutSeconds)
            ->withoutRedirecting()
            ->withBody($body, 'application/json')
            ->post($url)
            ->status();
    }
}

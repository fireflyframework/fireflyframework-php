<?php

declare(strict_types=1);

namespace Firefly\Webhooks\Tests\Support;

use Firefly\Webhooks\WebhookRequest;

/** Builds WebhookRequest values for validator tests without an HTTP kernel. */
final class WebhookRequests
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $form
     */
    public static function make(string $provider, string $body, array $headers = [], array $form = [], string $url = 'https://hooks.example.com/webhooks/x', string $contentType = 'application/json'): WebhookRequest
    {
        $lower = [];
        foreach ($headers as $name => $value) {
            $lower[strtolower($name)] = $value;
        }

        return new WebhookRequest($provider, 'POST', $url, $lower, $body, $form, $contentType, time());
    }
}

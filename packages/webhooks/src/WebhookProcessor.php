<?php

declare(strict_types=1);

namespace Firefly\Webhooks;

use Firefly\Eda\EventPublisher;
use Firefly\Kernel\Exception\Infrastructure\ServiceUnavailableException;
use Firefly\Webhooks\Exception\WebhookRejectedException;
use Firefly\Webhooks\Idempotency\WebhookIdempotencyStore;
use Firefly\Webhooks\Provider\WebhookProviderSettings;
use Firefly\Webhooks\Signature\SignatureValidatorFactory;
use JsonException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One inbound webhook, start to finish — in the order that keeps each step cheap for a hostile sender:
 *
 *   1. the provider must be configured (404) and the body within firefly.webhooks.max-body-bytes (413);
 *   2. the signature must verify, inside the replay window where the provider signs a timestamp (401);
 *   3. the body is decoded — a JSON object, form parameters, or kept raw — and a body that says it is JSON but is
 *      not is refused (400);
 *   4. the event type comes from the provider's event-type header, else its event-type field (a dotted path into
 *      the body), else `unknown`; the idempotency key from its idempotency header, else its idempotency field;
 *   5. a key already claimed answers 200 `duplicate` and publishes nothing — no key means no deduplication;
 *   6. the webhook is published to firefly/eda as `firefly.webhooks.event-type` on `firefly.webhooks.destination`
 *      (both templates over {provider} and {type}; a provider may name its own destination). A publish that fails
 *      RELEASES the claim and answers 503, so the provider's retry is processed instead of being dropped as a
 *      duplicate of a webhook nobody received.
 */
final class WebhookProcessor
{
    public function __construct(
        private readonly WebhooksSettings $settings,
        private readonly SignatureValidatorFactory $validators,
        private readonly WebhookIdempotencyStore $idempotency,
        private readonly EventPublisher $publisher,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function provider(string $id): WebhookProviderSettings
    {
        return $this->settings->provider($id) ?? throw WebhookRejectedException::unknownProvider($id);
    }

    public function process(WebhookRequest $request): WebhookResult
    {
        $provider = $this->provider($request->provider);

        if (strlen($request->body) > $this->settings->maxBodyBytes) {
            throw WebhookRejectedException::tooLarge($provider->id, $this->settings->maxBodyBytes);
        }

        $this->validators->for($provider)->verify($request);

        $body = $this->decode($request, $provider);
        $type = $this->eventTypeOf($request, $provider, $body);
        $key = $this->idempotencyKeyOf($request, $provider, $body);
        $eventId = self::uuid4();

        if ($key !== null && ! $this->idempotency->claim($provider->id, $key, $eventId, $this->settings->idempotencyTtl)) {
            $this->logger?->info("Duplicate [{$provider->id}] webhook {$key} ignored.");

            return WebhookResult::duplicate($provider->id, $key);
        }

        $replacements = ['{provider}' => $provider->id, '{type}' => $type];
        $eventType = strtr($this->settings->eventType, $replacements);
        $destination = strtr($provider->destination ?? $this->settings->destination, $replacements);

        try {
            $this->publisher->publish($destination, $eventType, [
                'id' => $eventId,
                'provider' => $provider->id,
                'type' => $type,
                'receivedAt' => gmdate(DATE_ATOM, $request->receivedAt),
                'idempotencyKey' => $key,
                'headers' => self::publishableHeaders($request->headers),
                'body' => $body,
            ], ['x-webhook-provider' => $provider->id, 'x-webhook-event-id' => $eventId]);
        } catch (Throwable $e) {
            if ($key !== null) {
                $this->idempotency->release($provider->id, $key);
            }

            throw new ServiceUnavailableException("The [{$provider->id}] webhook was verified but could not be published; the delivery should be retried.", 'WEBHOOK_PUBLISH_FAILED', $e);
        }

        return WebhookResult::accepted($eventId, $provider->id, $eventType, $destination, $key);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(WebhookRequest $request, WebhookProviderSettings $provider): array
    {
        if ($request->body === '') {
            return [];
        }

        if ($request->contentType === 'application/x-www-form-urlencoded') {
            return $request->form;
        }

        $looksJson = str_contains($request->contentType, 'json') || ($request->contentType === '' && str_starts_with(ltrim($request->body), '{'));
        if (! $looksJson) {
            return mb_check_encoding($request->body, 'UTF-8') ? ['raw' => $request->body] : ['rawBase64' => base64_encode($request->body)];
        }

        try {
            $decoded = json_decode($request->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw WebhookRejectedException::invalidPayload($provider->id, 'the JSON does not parse ('.$e->getMessage().')');
        }

        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw WebhookRejectedException::invalidPayload($provider->id, 'a JSON webhook body must be an object');
        }

        return $decoded;
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private function eventTypeOf(WebhookRequest $request, WebhookProviderSettings $provider, array $body): string
    {
        $type = $provider->eventTypeHeader !== '' ? $request->header($provider->eventTypeHeader) : null;
        $type ??= $provider->eventTypeField !== '' ? self::scalarAt($body, $provider->eventTypeField) : null;

        $type = preg_replace('/[^A-Za-z0-9._:\-]/', '_', (string) ($type ?? 'unknown'));

        return $type === null || $type === '' ? 'unknown' : substr($type, 0, 191);
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private function idempotencyKeyOf(WebhookRequest $request, WebhookProviderSettings $provider, array $body): ?string
    {
        $key = $provider->idempotencyHeader !== '' ? $request->header($provider->idempotencyHeader) : null;
        $key ??= $provider->idempotencyField !== '' ? self::scalarAt($body, $provider->idempotencyField) : null;

        if ($key === null || $key === '') {
            return null;
        }

        // A key longer than the column is hashed: the same delivery still maps to the same key.
        return strlen($key) > 191 ? hash('sha256', $key) : $key;
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private static function scalarAt(array $body, string $path): ?string
    {
        $current = $body;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return is_scalar($current) ? (string) $current : null;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private static function publishableHeaders(array $headers): array
    {
        unset($headers['authorization'], $headers['proxy-authorization'], $headers['cookie']);

        return $headers;
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

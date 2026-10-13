<?php

declare(strict_types=1);

namespace Firefly\Webhooks\Signature;

use Firefly\Webhooks\Exception\WebhookRejectedException;
use Firefly\Webhooks\WebhookRequest;

/** Verifies that a webhook was sent by the provider that holds the secret. Returns quietly, or refuses. */
interface SignatureValidator
{
    /**
     * @throws WebhookRejectedException WEBHOOK_SIGNATURE_INVALID or WEBHOOK_TIMESTAMP_OUT_OF_TOLERANCE
     */
    public function verify(WebhookRequest $request): void;
}

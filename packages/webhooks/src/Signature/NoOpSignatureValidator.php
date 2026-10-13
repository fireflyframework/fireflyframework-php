<?php

declare(strict_types=1);

namespace Firefly\Webhooks\Signature;

use Firefly\Webhooks\WebhookRequest;

/** validator `none`: accepts every request. Only for a sender on a network you trust; the choice is explicit. */
final class NoOpSignatureValidator implements SignatureValidator
{
    public function verify(WebhookRequest $request): void {}
}

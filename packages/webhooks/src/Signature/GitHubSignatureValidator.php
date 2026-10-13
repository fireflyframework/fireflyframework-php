<?php

declare(strict_types=1);

namespace Firefly\Webhooks\Signature;

use Firefly\Webhooks\WebhookRequest;

/**
 * GitHub (validator `github`): `X-Hub-Signature-256: sha256=<hex HMAC-SHA256 of the body>`. A named HMAC validator,
 * as PyFly's is. GitHub signs no timestamp, so replays are stopped by the idempotency store keyed on
 * `X-GitHub-Delivery` instead.
 */
final class GitHubSignatureValidator implements SignatureValidator
{
    private readonly HmacSignatureValidator $hmac;

    public function __construct(string $secret, string $header = 'X-Hub-Signature-256')
    {
        $this->hmac = new HmacSignatureValidator($secret, $header, 'sha256=', 'sha256', 'hex');
    }

    public function verify(WebhookRequest $request): void
    {
        $this->hmac->verify($request);
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Callbacks\Delivery;

/**
 * Signs a delivery: `X-Firefly-Timestamp: <unix>` and `X-Firefly-Signature: sha256=<hex HMAC-SHA256(secret,
 * "<timestamp>.<body>")>`. The timestamp is inside the signature so a captured delivery cannot be replayed later
 * (PyFly signs the body alone; spec Decision 12), and it is exactly what firefly/webhooks' `hmac` validator checks
 * with `timestamp-header: X-Firefly-Timestamp`. Header names are configurable for receivers that expect others.
 */
final class CallbackSigner
{
    public function __construct(
        private readonly string $signatureHeader = 'X-Firefly-Signature',
        private readonly string $timestampHeader = 'X-Firefly-Timestamp',
    ) {}

    /**
     * @return array<string, string>
     */
    public function sign(string $body, ?string $secret, int $timestamp): array
    {
        if ($secret === null || $secret === '') {
            return [];
        }

        return [
            $this->timestampHeader => (string) $timestamp,
            $this->signatureHeader => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
        ];
    }
}

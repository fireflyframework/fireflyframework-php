<?php

declare(strict_types=1);

namespace Firefly\Webhooks\Signature;

use Closure;
use Firefly\Webhooks\Exception\WebhookRejectedException;
use Firefly\Webhooks\WebhookRequest;

/**
 * The generic HMAC validator (validator `hmac`, PyFly's HmacSignatureValidator): the header carries
 * `<prefix><hmac(body)>`, hex or base64, with any algorithm hash_hmac() supports. With a timestamp header the signed
 * payload is "<timestamp>.<body>" and the timestamp must sit inside the replay window — the scheme firefly/callbacks
 * sends, so a LaraFly receiver verifies a LaraFly sender with `timestamp-header: X-Firefly-Timestamp`. Compared with
 * hash_equals(), so the comparison leaks no timing.
 */
final class HmacSignatureValidator implements SignatureValidator
{
    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param  (Closure(): int)|null  $clock  unix seconds
     */
    public function __construct(
        private readonly string $secret,
        private readonly string $header = 'X-Signature',
        private readonly string $prefix = 'sha256=',
        private readonly string $algorithm = 'sha256',
        private readonly string $encoding = 'hex',
        private readonly string $timestampHeader = '',
        private readonly float $tolerance = 300.0,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function verify(WebhookRequest $request): void
    {
        $signature = $request->header($this->header);
        if ($signature === null || $signature === '') {
            throw WebhookRejectedException::signatureInvalid($request->provider, "the {$this->header} header is missing");
        }

        if ($this->prefix !== '') {
            if (! str_starts_with($signature, $this->prefix)) {
                throw WebhookRejectedException::signatureInvalid($request->provider, "the signature does not start with {$this->prefix}");
            }
            $signature = substr($signature, strlen($this->prefix));
        }

        $signed = $request->body;
        if ($this->timestampHeader !== '') {
            $timestamp = $request->header($this->timestampHeader);
            if ($timestamp === null || preg_match('/^\d{1,12}$/', $timestamp) !== 1) {
                throw WebhookRejectedException::signatureInvalid($request->provider, "the {$this->timestampHeader} header is missing or not a unix timestamp");
            }

            ReplayWindow::assertWithin((int) $timestamp, $this->tolerance, ($this->clock)(), $request->provider);
            $signed = $timestamp.'.'.$request->body;
        }

        if (! hash_equals($this->expected($signed), $signature)) {
            throw WebhookRejectedException::signatureInvalid($request->provider, 'the signature does not match the body');
        }
    }

    public function expected(string $signed): string
    {
        $raw = hash_hmac($this->algorithm, $signed, $this->secret, true);

        return $this->encoding === 'base64' ? base64_encode($raw) : bin2hex($raw);
    }
}

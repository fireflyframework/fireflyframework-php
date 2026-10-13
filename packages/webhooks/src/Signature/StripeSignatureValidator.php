<?php

declare(strict_types=1);

namespace Firefly\Webhooks\Signature;

use Closure;
use Firefly\Webhooks\Exception\WebhookRejectedException;
use Firefly\Webhooks\WebhookRequest;

/**
 * Stripe (validator `stripe`): `Stripe-Signature: t=<unix>,v1=<hex>[,v1=<hex>…]`, HMAC-SHA256 over "<t>.<body>".
 * Any v1 may match — Stripe sends one per active secret while a secret is being rolled — and t must sit inside the
 * replay window (Stripe's own default tolerance is the same five minutes).
 */
final class StripeSignatureValidator implements SignatureValidator
{
    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param  (Closure(): int)|null  $clock  unix seconds
     */
    public function __construct(
        private readonly string $secret,
        private readonly string $header = 'Stripe-Signature',
        private readonly float $tolerance = 300.0,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function verify(WebhookRequest $request): void
    {
        $value = $request->header($this->header);
        if ($value === null || $value === '') {
            throw WebhookRejectedException::signatureInvalid($request->provider, "the {$this->header} header is missing");
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $value) as $pair) {
            [$key, $part] = array_pad(explode('=', trim($pair), 2), 2, '');
            if ($key === 't') {
                $timestamp = $part;
            } elseif ($key === 'v1') {
                $signatures[] = $part;
            }
        }

        if ($timestamp === null || preg_match('/^\d{1,12}$/', $timestamp) !== 1) {
            throw WebhookRejectedException::signatureInvalid($request->provider, 'the header has no t= timestamp');
        }
        if ($signatures === []) {
            throw WebhookRejectedException::signatureInvalid($request->provider, 'the header has no v1= signature');
        }

        ReplayWindow::assertWithin((int) $timestamp, $this->tolerance, ($this->clock)(), $request->provider);

        $expected = hash_hmac('sha256', $timestamp.'.'.$request->body, $this->secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return;
            }
        }

        throw WebhookRejectedException::signatureInvalid($request->provider, 'no v1 signature matches the body');
    }
}

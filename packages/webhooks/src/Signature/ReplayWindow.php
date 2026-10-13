<?php

declare(strict_types=1);

namespace Firefly\Webhooks\Signature;

use Firefly\Webhooks\Exception\WebhookRejectedException;

/**
 * The replay window: a signed timestamp further than $tolerance seconds from now — in either direction, since a
 * clock ahead of ours is as suspect as one behind — is refused. A tolerance of 0 switches the check off.
 */
final class ReplayWindow
{
    public static function assertWithin(int $timestamp, float $tolerance, int $now, string $provider): void
    {
        if ($tolerance > 0 && abs($now - $timestamp) > $tolerance) {
            throw WebhookRejectedException::replayed($provider, $timestamp, $tolerance);
        }
    }
}

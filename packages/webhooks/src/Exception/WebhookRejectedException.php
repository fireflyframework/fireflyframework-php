<?php

declare(strict_types=1);

namespace Firefly\Webhooks\Exception;

use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Exception\FireflyException;

/**
 * Every way an inbound webhook is refused, each with the stable code a sender's dashboard and an operator's alert
 * can key on, and the provider id as a problem+json extension member. The messages name what failed — never the
 * secret, never the expected signature.
 */
final class WebhookRejectedException extends FireflyException
{
    public static function unknownProvider(string $provider): self
    {
        return new self("No webhook provider [{$provider}] is configured.", 'WEBHOOK_PROVIDER_NOT_FOUND', 404, ErrorCategory::Business, ErrorSeverity::Warning, extensions: ['provider' => $provider]);
    }

    public static function tooLarge(string $provider, int $limit): self
    {
        return new self("The [{$provider}] webhook body is larger than firefly.webhooks.max-body-bytes ({$limit} bytes).", 'WEBHOOK_PAYLOAD_TOO_LARGE', 413, ErrorCategory::Validation, ErrorSeverity::Warning, extensions: ['provider' => $provider]);
    }

    public static function signatureInvalid(string $provider, string $reason): self
    {
        return new self("The [{$provider}] webhook signature is invalid: {$reason}.", 'WEBHOOK_SIGNATURE_INVALID', 401, ErrorCategory::Security, ErrorSeverity::Warning, extensions: ['provider' => $provider]);
    }

    public static function replayed(string $provider, int $timestamp, float $tolerance): self
    {
        return new self("The [{$provider}] webhook was signed at {$timestamp}, outside the {$tolerance}s replay window.", 'WEBHOOK_TIMESTAMP_OUT_OF_TOLERANCE', 401, ErrorCategory::Security, ErrorSeverity::Warning, extensions: ['provider' => $provider]);
    }

    public static function invalidPayload(string $provider, string $reason): self
    {
        return new self("The [{$provider}] webhook body cannot be read: {$reason}.", 'WEBHOOK_PAYLOAD_INVALID', 400, ErrorCategory::Validation, ErrorSeverity::Warning, extensions: ['provider' => $provider]);
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Webhooks\Signature;

use Closure;
use Firefly\Webhooks\Provider\SignatureKind;
use Firefly\Webhooks\Provider\WebhookProviderSettings;

/** Builds the validator a provider's settings describe. The clock is shared so a test can pin "now". */
final class SignatureValidatorFactory
{
    /**
     * @param  Closure(): int  $clock  unix seconds
     */
    public function __construct(private readonly Closure $clock) {}

    public function for(WebhookProviderSettings $provider): SignatureValidator
    {
        return match ($provider->validator) {
            SignatureKind::None => new NoOpSignatureValidator,
            SignatureKind::Hmac => new HmacSignatureValidator($provider->secret, $provider->signatureHeader, $provider->signaturePrefix, $provider->algorithm, $provider->encoding, $provider->timestampHeader, $provider->tolerance, $this->clock),
            SignatureKind::Stripe => new StripeSignatureValidator($provider->secret, $provider->signatureHeader, $provider->tolerance, $this->clock),
            SignatureKind::GitHub => new GitHubSignatureValidator($provider->secret, $provider->signatureHeader),
            SignatureKind::Twilio => new TwilioSignatureValidator($provider->secret, $provider->signatureHeader),
        };
    }
}

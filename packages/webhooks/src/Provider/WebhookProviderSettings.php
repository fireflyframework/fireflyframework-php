<?php

declare(strict_types=1);

namespace Firefly\Webhooks\Provider;

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Webhooks\WebhooksSettings;

/**
 * One entry of firefly.webhooks.providers, read once at boot. The array key is the provider id — the last segment
 * of its endpoint — and every setting not given takes its kind's default (SignatureKind::defaults()). A provider
 * that could never verify anything (a signing kind without a secret, an unknown algorithm) refuses the boot,
 * naming the key: discovering it from the first rejected delivery would be discovering it in production.
 */
final readonly class WebhookProviderSettings
{
    public function __construct(
        public string $id,
        public SignatureKind $validator,
        public string $secret,
        public string $signatureHeader,
        public string $signaturePrefix,
        public string $algorithm,
        public string $encoding,
        public string $timestampHeader,
        public float $tolerance,
        public string $idempotencyHeader,
        public string $idempotencyField,
        public string $eventTypeHeader,
        public string $eventTypeField,
        public ?string $destination,
        public ?string $publicUrl,
    ) {}

    /**
     * @param  array<array-key, mixed>  $raw
     */
    public static function fromArray(string $id, array $raw, float $defaultTolerance): self
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $id) !== 1) {
            throw new ConfigurationException("firefly.webhooks.providers.{$id}: a provider id is 1 to 64 letters, digits, dots, underscores or dashes, starting with a letter or digit.");
        }

        $where = "firefly.webhooks.providers.{$id}";
        $kindName = self::text($raw, 'validator', 'hmac');
        $kind = SignatureKind::tryFrom($kindName)
            ?? throw new ConfigurationException("{$where}.validator must be one of hmac, stripe, github, twilio or none; got '{$kindName}'.");
        $defaults = $kind->defaults();

        $secret = self::text($raw, 'secret', '');
        if ($kind !== SignatureKind::None && $secret === '') {
            throw new ConfigurationException("{$where}.secret is empty: a {$kind->value} validator cannot verify anything without it.");
        }

        $algorithm = self::text($raw, 'algorithm', 'sha256');
        if (! in_array($algorithm, hash_hmac_algos(), true)) {
            throw new ConfigurationException("{$where}.algorithm '{$algorithm}' is not an HMAC algorithm this PHP supports.");
        }

        $encoding = self::text($raw, 'encoding', 'hex');
        if (! in_array($encoding, ['hex', 'base64'], true)) {
            throw new ConfigurationException("{$where}.encoding must be 'hex' or 'base64'; got '{$encoding}'.");
        }

        return new self(
            id: $id,
            validator: $kind,
            secret: $secret,
            signatureHeader: self::text($raw, 'signature-header', $defaults['signature-header']),
            signaturePrefix: self::text($raw, 'signature-prefix', $defaults['signature-prefix']),
            algorithm: $algorithm,
            encoding: $encoding,
            timestampHeader: self::text($raw, 'timestamp-header', ''),
            tolerance: WebhooksSettings::seconds($raw['tolerance'] ?? null, $defaultTolerance, "{$where}.tolerance"),
            idempotencyHeader: self::text($raw, 'idempotency-header', $defaults['idempotency-header']),
            idempotencyField: self::text($raw, 'idempotency-field', $defaults['idempotency-field']),
            eventTypeHeader: self::text($raw, 'event-type-header', $defaults['event-type-header']),
            eventTypeField: self::text($raw, 'event-type-field', $defaults['event-type-field']),
            destination: self::optional($raw, 'destination'),
            publicUrl: self::optional($raw, 'public-url'),
        );
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    private static function text(array $raw, string $key, string $default): string
    {
        $value = $raw[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    private static function optional(array $raw, string $key): ?string
    {
        $value = $raw[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}

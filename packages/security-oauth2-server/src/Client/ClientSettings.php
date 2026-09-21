<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Client;

use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * A client's own switches (Spring's ClientSettings): whether it must use PKCE even when the server does not
 * demand it of everyone, whether the user is asked for consent (defaulting to `consent.required`), and the JWK
 * set a `private_key_jwt` client signs its assertions with.
 */
final readonly class ClientSettings
{
    /**
     * @param  array<string,mixed>|null  $jwkSet
     */
    public function __construct(
        public bool $requireProofKey = false,
        public bool $requireAuthorizationConsent = true,
        public ?array $jwkSet = null,
    ) {}

    /**
     * @param  array<string,mixed>  $data
     */
    public static function fromArray(array $data, bool $defaultConsent, string $client): self
    {
        $jwkSet = $data['jwk_set'] ?? null;
        if ($jwkSet !== null && (! is_array($jwkSet) || ! is_array($jwkSet['keys'] ?? null))) {
            throw new ConfigurationException("Client [{$client}]: client_settings.jwk_set must be a decoded JWKS document ({keys: [...]}).");
        }

        /** @var array<string,mixed>|null $jwkSet */
        return new self(
            requireProofKey: self::bool($data, 'require_pkce', false, $client),
            requireAuthorizationConsent: self::bool($data, 'require_authorization_consent', $defaultConsent, $client),
            jwkSet: $jwkSet,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return ['require_pkce' => $this->requireProofKey, 'require_authorization_consent' => $this->requireAuthorizationConsent, 'jwk_set' => $this->jwkSet];
    }

    /**
     * The rule Config::bool() applies to a server-wide key, so a client block written as `env('WEBAPP_PKCE')`
     * behaves like every other boolean in the framework: a real bool, or a string/int filter_var recognises
     * (`"off"`, `"no"`, `"0"` are false), and a refusal at boot for anything else — a raw `(bool)` cast would
     * read `"false"` and `"off"` as true.
     *
     * @param  array<string,mixed>  $data
     */
    private static function bool(array $data, string $key, bool $default, string $client): bool
    {
        $value = $data[$key] ?? $default;
        if (is_bool($value)) {
            return $value;
        }
        $normalized = is_string($value) || is_int($value) ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
        if ($normalized === null) {
            throw new ConfigurationException("Client [{$client}]: client_settings.{$key} must be true or false.");
        }

        return $normalized;
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Client;

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;
use Firefly\Security\OAuth2\Server\Settings\OAuth2TokenFormat;

/**
 * A client's token lifetimes and formats (Spring's TokenSettings), each defaulting to the server-wide key of
 * the same name so a client block only has to say what differs.
 */
final readonly class TokenSettings
{
    public function __construct(
        public int $accessTokenTtl,
        public OAuth2TokenFormat $accessTokenFormat,
        public int $refreshTokenTtl,
        public bool $reuseRefreshTokens,
        public int $authorizationCodeTtl,
        public int $idTokenTtl,
    ) {}

    public static function defaults(AuthorizationServerSettings $settings): self
    {
        return new self(
            $settings->accessTokenTtl,
            $settings->accessTokenFormat,
            $settings->refreshTokenTtl,
            $settings->reuseRefreshTokens,
            $settings->authorizationCodeTtl,
            $settings->idTokenTtl,
        );
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public static function fromArray(array $data, AuthorizationServerSettings $defaults, string $client): self
    {
        $format = $data['access_token_format'] ?? $defaults->accessTokenFormat->value;
        $parsed = is_string($format) ? OAuth2TokenFormat::tryFrom($format) : null;
        if ($parsed === null) {
            throw new ConfigurationException("Client [{$client}]: token_settings.access_token_format must be self_contained or reference.");
        }

        return new self(
            self::seconds($data, 'access_token_ttl', $defaults->accessTokenTtl, $client),
            $parsed,
            self::seconds($data, 'refresh_token_ttl', $defaults->refreshTokenTtl, $client),
            self::bool($data, 'reuse_refresh_tokens', $defaults->reuseRefreshTokens, $client),
            self::seconds($data, 'authorization_code_ttl', $defaults->authorizationCodeTtl, $client),
            self::seconds($data, 'id_token_ttl', $defaults->idTokenTtl, $client),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'access_token_ttl' => $this->accessTokenTtl,
            'access_token_format' => $this->accessTokenFormat->value,
            'refresh_token_ttl' => $this->refreshTokenTtl,
            'reuse_refresh_tokens' => $this->reuseRefreshTokens,
            'authorization_code_ttl' => $this->authorizationCodeTtl,
            'id_token_ttl' => $this->idTokenTtl,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private static function seconds(array $data, string $key, int $default, string $client): int
    {
        $value = $data[$key] ?? $default;
        if (! is_int($value) || $value < 1) {
            throw new ConfigurationException("Client [{$client}]: token_settings.{$key} must be a positive number of seconds.");
        }

        return $value;
    }

    /**
     * The rule Config::bool() applies to the server-wide `refresh_token.reuse`: a real bool, or a string/int
     * filter_var recognises (`"off"`, `"no"`, `"0"` are false), and a refusal at boot for anything else. This is
     * the switch that turns rotation and reuse detection OFF, so a raw `(bool)` cast — which reads `"false"`
     * and `"off"` as true — would silently pick the weaker setting for a client whose block says
     * `env('WEBAPP_REUSE')` with `WEBAPP_REUSE=off`.
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
            throw new ConfigurationException("Client [{$client}]: token_settings.{$key} must be true or false.");
        }

        return $normalized;
    }
}

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
            (bool) ($data['reuse_refresh_tokens'] ?? $defaults->reuseRefreshTokens),
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
}

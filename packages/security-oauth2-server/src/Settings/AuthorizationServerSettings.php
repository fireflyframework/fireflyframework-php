<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Settings;

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\Web\Settings\FormLoginSettings;
use Illuminate\Http\Request;

/**
 * Everything the authorization server reads from `firefly.security.oauth2.server.*` (Spring's
 * AuthorizationServerSettings), built once from the Config port and validated at construction so a typo in the
 * algorithm, a relative issuer or two endpoints on one path is a boot refusal naming the key — never a 500 on the
 * first token request.
 *
 * THE ISSUER IS THE BASE OF EVERY PUBLISHED URL and the value of every `iss` claim; it defaults to `app.url` so an
 * application that never set the key issues tokens for the address it already says it is served at. The endpoint
 * PATHS are matched against `Request::path()` through FormLoginSettings::path() — leading slash, no query, no
 * trailing slash — so a front-controller prefix or a query string never breaks the match, and they are published
 * as `endpointUrl($path)`, the issuer followed by the path, exactly as Spring builds them.
 *
 * EVERY READ BELOW SPELLS ITS KEY OUT IN FULL rather than concatenating PREFIX, on purpose: tests/ConfigReferenceTest.php
 * discovers the keys the framework reads by finding the literal `'firefly.…'` string inside each Config-port call, so a
 * read written as `"{$p}.enabled"` is a key that guard can no longer see — and the reference in skeleton/config/firefly.php
 * would rot without anything saying so. PREFIX is for the refusal sentences only.
 */
final readonly class AuthorizationServerSettings
{
    public const string PREFIX = 'firefly.security.oauth2.server';

    /**
     * @param  list<array{key: string, key_id: string}>  $previousKeys
     */
    public function __construct(
        public bool $enabled = false,
        public string $issuer = 'http://localhost',
        public string $authorizationEndpoint = '/oauth2/authorize',
        public string $tokenEndpoint = '/oauth2/token',
        public string $jwkSetEndpoint = '/oauth2/jwks',
        public string $tokenIntrospectionEndpoint = '/oauth2/introspect',
        public string $tokenRevocationEndpoint = '/oauth2/revoke',
        public string $oidcUserInfoEndpoint = '/userinfo',
        public string $oidcLogoutEndpoint = '/connect/logout',
        public string $oidcClientRegistrationEndpoint = '',
        public string $signingKey = '',
        public string $keyId = '',
        public string $algorithm = 'RS256',
        public array $previousKeys = [],
        public OAuth2TokenFormat $accessTokenFormat = OAuth2TokenFormat::SelfContained,
        public int $accessTokenTtl = 300,
        public int $refreshTokenTtl = 3600,
        public bool $reuseRefreshTokens = false,
        public int $authorizationCodeTtl = 300,
        public int $idTokenTtl = 1800,
        public bool $requirePkce = true,
        public bool $requireProofKeyForPublicClients = true,
        public bool $consentRequired = true,
        public ?string $consentView = null,
        public string $clientsDriver = 'memory',
        public string $authorizationsDriver = 'memory',
        public bool $purgeEnabled = false,
        public string $purgeCron = '*/15 * * * *',
        public bool $rateLimitEnabled = false,
        public int $rateLimitMaxTokens = 60,
        public float $rateLimitRefillRate = 1.0,
    ) {
        self::assertIssuer($this->issuer);
        self::assertAlgorithm($this->algorithm);
        self::assertDriver($this->clientsDriver, self::PREFIX.'.clients.driver');
        self::assertDriver($this->authorizationsDriver, self::PREFIX.'.authorizations.driver');
        self::assertPositive($this->accessTokenTtl, self::PREFIX.'.access_token.ttl');
        self::assertPositive($this->refreshTokenTtl, self::PREFIX.'.refresh_token.ttl');
        self::assertPositive($this->authorizationCodeTtl, self::PREFIX.'.authorization_code.ttl');
        self::assertPositive($this->idTokenTtl, self::PREFIX.'.id_token.ttl');
        self::assertPositive($this->rateLimitMaxTokens, self::PREFIX.'.rate_limit.max_tokens');
        self::assertPositiveRate($this->rateLimitRefillRate, self::PREFIX.'.rate_limit.refill_rate');
        if ($this->purgeCron === '') {
            throw new ConfigurationException(self::PREFIX.'.authorizations.purge.cron must be a cron expression.');
        }
        self::assertEndpoints($this->endpointPaths());
    }

    public static function fromConfig(Config $config): self
    {
        $view = $config->string('firefly.security.oauth2.server.consent.view', '');
        $format = $config->string('firefly.security.oauth2.server.access_token.format', OAuth2TokenFormat::SelfContained->value);

        /** @var mixed $previous */
        $previous = $config->get('firefly.security.oauth2.server.jwt.previous_keys', []);
        /** @var mixed $refillRate */
        $refillRate = $config->get('firefly.security.oauth2.server.rate_limit.refill_rate', 1.0);

        return new self(
            enabled: $config->bool('firefly.security.oauth2.server.enabled', false),
            issuer: $config->string('firefly.security.oauth2.server.issuer', $config->string('app.url', 'http://localhost')),
            authorizationEndpoint: $config->string('firefly.security.oauth2.server.authorization_endpoint', '/oauth2/authorize'),
            tokenEndpoint: $config->string('firefly.security.oauth2.server.token_endpoint', '/oauth2/token'),
            jwkSetEndpoint: $config->string('firefly.security.oauth2.server.jwk_set_endpoint', '/oauth2/jwks'),
            tokenIntrospectionEndpoint: $config->string('firefly.security.oauth2.server.token_introspection_endpoint', '/oauth2/introspect'),
            tokenRevocationEndpoint: $config->string('firefly.security.oauth2.server.token_revocation_endpoint', '/oauth2/revoke'),
            oidcUserInfoEndpoint: $config->string('firefly.security.oauth2.server.oidc_user_info_endpoint', '/userinfo'),
            oidcLogoutEndpoint: $config->string('firefly.security.oauth2.server.oidc_logout_endpoint', '/connect/logout'),
            oidcClientRegistrationEndpoint: $config->string('firefly.security.oauth2.server.oidc_client_registration_endpoint', ''),
            signingKey: $config->string('firefly.security.oauth2.server.jwt.signing_key', ''),
            keyId: $config->string('firefly.security.oauth2.server.jwt.key_id', ''),
            algorithm: $config->string('firefly.security.oauth2.server.jwt.algorithm', 'RS256'),
            previousKeys: self::previousKeys($previous),
            accessTokenFormat: OAuth2TokenFormat::tryFrom($format) ?? throw new ConfigurationException(self::PREFIX.".access_token.format must be self_contained or reference; got `{$format}`."),
            accessTokenTtl: $config->int('firefly.security.oauth2.server.access_token.ttl', 300),
            refreshTokenTtl: $config->int('firefly.security.oauth2.server.refresh_token.ttl', 3600),
            reuseRefreshTokens: $config->bool('firefly.security.oauth2.server.refresh_token.reuse', false),
            authorizationCodeTtl: $config->int('firefly.security.oauth2.server.authorization_code.ttl', 300),
            idTokenTtl: $config->int('firefly.security.oauth2.server.id_token.ttl', 1800),
            requirePkce: $config->bool('firefly.security.oauth2.server.require_pkce', true),
            requireProofKeyForPublicClients: $config->bool('firefly.security.oauth2.server.require_proof_key_for_public_clients', true),
            consentRequired: $config->bool('firefly.security.oauth2.server.consent.required', true),
            consentView: $view === '' ? null : $view,
            clientsDriver: $config->string('firefly.security.oauth2.server.clients.driver', 'memory'),
            authorizationsDriver: $config->string('firefly.security.oauth2.server.authorizations.driver', 'memory'),
            purgeEnabled: $config->bool('firefly.security.oauth2.server.authorizations.purge.enabled', false),
            purgeCron: $config->string('firefly.security.oauth2.server.authorizations.purge.cron', '*/15 * * * *'),
            rateLimitEnabled: $config->bool('firefly.security.oauth2.server.rate_limit.enabled', false),
            rateLimitMaxTokens: $config->int('firefly.security.oauth2.server.rate_limit.max_tokens', 60),
            rateLimitRefillRate: self::rate($refillRate, self::PREFIX.'.rate_limit.refill_rate'),
        );
    }

    /** The absolute URL the metadata publishes for an endpoint path: the issuer followed by the path. */
    public function endpointUrl(string $path): string
    {
        return rtrim($this->issuer, '/').$path;
    }

    /** Whether the request is addressed to the endpoint — by path alone, the way every security filter compares URLs. */
    public function isEndpoint(Request $request, string $path): bool
    {
        return FormLoginSettings::path('/'.$request->path()) === FormLoginSettings::path($path);
    }

    public function hasClientRegistration(): bool
    {
        return $this->oidcClientRegistrationEndpoint !== '';
    }

    /**
     * @return list<string>
     */
    private function endpointPaths(): array
    {
        $paths = [
            self::PREFIX.'.authorization_endpoint' => $this->authorizationEndpoint,
            self::PREFIX.'.token_endpoint' => $this->tokenEndpoint,
            self::PREFIX.'.jwk_set_endpoint' => $this->jwkSetEndpoint,
            self::PREFIX.'.token_introspection_endpoint' => $this->tokenIntrospectionEndpoint,
            self::PREFIX.'.token_revocation_endpoint' => $this->tokenRevocationEndpoint,
            self::PREFIX.'.oidc_user_info_endpoint' => $this->oidcUserInfoEndpoint,
            self::PREFIX.'.oidc_logout_endpoint' => $this->oidcLogoutEndpoint,
        ];
        if ($this->oidcClientRegistrationEndpoint !== '') {
            $paths[self::PREFIX.'.oidc_client_registration_endpoint'] = $this->oidcClientRegistrationEndpoint;
        }

        foreach ($paths as $key => $path) {
            if (! str_starts_with($path, '/') || strlen($path) < 2) {
                throw new ConfigurationException("{$key} must be an absolute path starting with `/`; got `{$path}`.");
            }
        }

        return array_values(array_map(static fn (string $path): string => FormLoginSettings::path($path), $paths));
    }

    /**
     * @param  list<string>  $paths
     */
    private static function assertEndpoints(array $paths): void
    {
        if (count(array_unique($paths)) !== count($paths)) {
            throw new ConfigurationException(self::PREFIX.' endpoint paths must be distinct; two endpoints share one path.');
        }
    }

    private static function assertIssuer(string $issuer): void
    {
        $parts = parse_url($issuer);
        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new ConfigurationException(self::PREFIX.".issuer must be an absolute URL with no query or fragment; got `{$issuer}`.");
        }
    }

    private static function assertAlgorithm(string $algorithm): void
    {
        if (! in_array($algorithm, ['RS256', 'ES256'], true)) {
            throw new ConfigurationException(self::PREFIX.".jwt.algorithm must be RS256 or ES256; got `{$algorithm}`.");
        }
    }

    private static function assertDriver(string $driver, string $key): void
    {
        if (! in_array($driver, ['memory', 'eloquent'], true)) {
            throw new ConfigurationException("{$key} must be memory or eloquent; got `{$driver}`.");
        }
    }

    private static function assertPositive(int $value, string $key): void
    {
        if ($value < 1) {
            throw new ConfigurationException("{$key} must be a positive number of seconds; got {$value}.");
        }
    }

    /**
     * A refill rate is the one float setting here, and the Config port has no float accessor — so the raw value is
     * read and gated on is_numeric, the way TraceSamplerFactory reads its sampling ratio. A `(float)` cast of
     * whatever string() returned is the trap this avoids: `'fast'` and `''` cast to 0.0 — a bucket that never
     * refills, so the token endpoint would answer 429 forever after the first burst — and the locale-style `'1,5'`
     * truncates to 1.0 without a word said. Numeric strings are welcome because .env values arrive as strings.
     */
    private static function rate(mixed $raw, string $key): float
    {
        if (! is_numeric($raw)) {
            $shown = is_string($raw) ? $raw : get_debug_type($raw);

            throw new ConfigurationException("{$key} must be a positive number of tokens per second; got `{$shown}`.");
        }

        return (float) $raw;
    }

    /** The sign half of the refill-rate check, on the constructor so a hand-built instance is validated too. */
    private static function assertPositiveRate(float $value, string $key): void
    {
        if (! is_finite($value) || $value <= 0.0) {
            throw new ConfigurationException("{$key} must be a positive number of tokens per second; got {$value}.");
        }
    }

    /**
     * @return list<array{key: string, key_id: string}>
     */
    private static function previousKeys(mixed $configured): array
    {
        if (! is_array($configured)) {
            throw new ConfigurationException(self::PREFIX.'.jwt.previous_keys must be a list of {key, key_id} entries.');
        }

        $keys = [];
        foreach ($configured as $entry) {
            if (! is_array($entry) || ! is_string($entry['key'] ?? null) || $entry['key'] === '') {
                throw new ConfigurationException(self::PREFIX.'.jwt.previous_keys: every entry needs a `key` (a PEM or a path) and may name a `key_id`.');
            }
            $keyId = $entry['key_id'] ?? '';
            $keys[] = ['key' => $entry['key'], 'key_id' => is_string($keyId) ? $keyId : ''];
        }

        return $keys;
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Registration;

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\OAuth2\Client\Discovery\OidcDiscovery;
use Firefly\Security\OAuth2\Client\OAuth2ClientSettings;

/**
 * Turns the configured maps into ClientRegistrations (Spring Boot's OAuth2ClientPropertiesMapper), in two
 * phases so a boot can refuse what it can see without touching the network:
 *
 *   plan()      — the STATIC facts of one registration, validated: a client_id; a grant type and a client
 *                 authentication method the package knows (configured, else the preset's, else deduced from
 *                 the secret); a secret whenever the method presents one; a provider that is a preset
 *                 (CommonOAuth2Provider), a configured `provider.{id}` block, or the preset overlaid by the
 *                 block; an `issuer_uri` OR the endpoints the grant needs spelled out
 *                 (token_uri; authorization_uri for authorization_code; jwk_set_uri when `openid` is requested,
 *                 because the id token must be verified; user_info_uri when it is not, because the userinfo
 *                 endpoint is then the only source of a principal); an issuer for the per-tenant presets; no
 *                 unknown setting (a `client-id` typo is refused, not ignored). validate() runs it for every
 *                 registration and is what the bean constructor and the wiring pass call.
 *   resolve()   — the plan plus discovery: when the provider has an issuer_uri and any endpoint the grant needs
 *                 is still unset, OidcDiscovery fills every unset endpoint from the document (an explicit key
 *                 always wins, as in Spring Boot). Memoised per id for the life of the process.
 *
 * WHICH PROVIDER A REGISTRATION USES: `provider` when set, else the registration id itself (Spring Boot's rule),
 * so `registration.google` needs no `provider` line at all.
 */
final class OAuth2ClientPropertiesMapper
{
    private const array REGISTRATION_KEYS = ['provider', 'client_id', 'client_secret', 'client_authentication_method', 'authorization_grant_type', 'redirect_uri', 'scope', 'client_name', 'pkce'];

    private const array PROVIDER_KEYS = ['issuer_uri', 'authorization_uri', 'token_uri', 'jwk_set_uri', 'user_info_uri', 'user_name_attribute', 'end_session_uri'];

    private const string PREFIX = 'firefly.security.oauth2.client.';

    /** @var array<string, ClientRegistration> */
    private array $resolved = [];

    public function __construct(
        private readonly OAuth2ClientProperties $properties,
        private readonly OidcDiscovery $discovery,
        private readonly OAuth2ClientSettings $settings,
    ) {}

    /**
     * @return list<string>
     */
    public function registrationIds(): array
    {
        return array_keys($this->properties->registrations);
    }

    public function has(string $registrationId): bool
    {
        return isset($this->properties->registrations[$registrationId]);
    }

    /** Every static rule, for every registration — the boot refusal. Never fetches. */
    public function validate(): void
    {
        foreach ($this->registrationIds() as $id) {
            $this->plan($id);
        }
    }

    public function registration(string $registrationId): ClientRegistration
    {
        return $this->resolved[$registrationId] ??= $this->resolve($this->plan($registrationId));
    }

    /**
     * @return array{id: string, key: string, providerKey: string, clientId: string, clientSecret: string, method: ClientAuthenticationMethod, grant: AuthorizationGrantType, redirectUri: string, scopes: list<string>, clientName: string, pkce: bool, provider: array<string, ?string>}
     */
    private function plan(string $id): array
    {
        $registration = $this->properties->registrations[$id] ?? throw new ConfigurationException("There is no client registration [{$id}] under ".self::PREFIX.'registration.');
        $key = self::PREFIX."registration.{$id}";
        self::assertKnownKeys($registration, self::REGISTRATION_KEYS, $key);

        $providerId = self::string($registration, 'provider', $key) ?? $id;
        $preset = CommonOAuth2Provider::tryFromId($providerId);
        $configured = $this->properties->providers[$providerId] ?? null;
        if ($preset === null && $configured === null) {
            throw new ConfigurationException("{$key} names the provider [{$providerId}], which is neither a preset (google, github, okta, keycloak, microsoft) nor configured under ".self::PREFIX."provider.{$providerId}.");
        }
        $providerKey = self::PREFIX.'provider.'.$providerId;
        if ($configured !== null) {
            self::assertKnownKeys($configured, self::PROVIDER_KEYS, $providerKey);
        }
        $provider = self::providerBlock($preset, $configured ?? [], $providerKey);
        if ($preset?->requiresIssuer() === true && $provider['issuer_uri'] === null) {
            throw new ConfigurationException("{$providerKey}.issuer_uri is required: {$preset->value} is a per-tenant provider, and the tenant's issuer is where its endpoints are discovered.");
        }

        $defaults = $preset?->registration() ?? [];

        $clientId = self::string($registration, 'client_id', $key);
        if ($clientId === null || $clientId === '') {
            throw new ConfigurationException("{$key}.client_id is required.");
        }
        $clientSecret = self::string($registration, 'client_secret', $key) ?? '';

        $grantValue = self::string($registration, 'authorization_grant_type', $key) ?? AuthorizationGrantType::AuthorizationCode->value;
        $grant = AuthorizationGrantType::tryFrom($grantValue);
        if ($grant === null || $grant === AuthorizationGrantType::RefreshToken) {
            throw new ConfigurationException("{$key}.authorization_grant_type must be authorization_code or client_credentials; got `{$grantValue}`.");
        }

        // The method: configured, else the preset's (Google and GitHub say client_secret_basic, because a web
        // OAuth app there is never public), else deduced from the secret — Basic when there is one, a public
        // client when there is not. A method that presents a secret the registration does not have is refused.
        $presetMethod = $defaults['client_authentication_method'] ?? null;
        $methodValue = self::string($registration, 'client_authentication_method', $key)
            ?? (is_string($presetMethod) ? $presetMethod : ($clientSecret === '' ? ClientAuthenticationMethod::None->value : ClientAuthenticationMethod::ClientSecretBasic->value));
        $method = ClientAuthenticationMethod::tryFrom($methodValue) ?? throw new ConfigurationException("{$key}.client_authentication_method must be client_secret_basic, client_secret_post or none; got `{$methodValue}`.");
        if ($method !== ClientAuthenticationMethod::None && $clientSecret === '') {
            throw new ConfigurationException("{$key}.client_secret is required for client_authentication_method {$method->value}; a public client says `client_authentication_method: none` and uses PKCE.");
        }

        $scopes = self::scopes($registration['scope'] ?? $defaults['scope'] ?? [], $key);
        $pkce = self::bool($registration, 'pkce', $key) ?? true;
        $presetName = $defaults['client_name'] ?? null;
        $clientName = self::string($registration, 'client_name', $key) ?? (is_string($presetName) ? $presetName : $id);
        $redirectUri = self::string($registration, 'redirect_uri', $key) ?? '{baseUrl}'.$this->settings->redirectionEndpointBaseUri.'/{registrationId}';

        if ($provider['issuer_uri'] === null) {
            $openId = in_array('openid', $scopes, true);
            if ($provider['token_uri'] === null) {
                throw new ConfigurationException("{$providerKey}.token_uri is required when the provider has no issuer_uri to discover it from.");
            }
            if ($grant === AuthorizationGrantType::AuthorizationCode) {
                if ($provider['authorization_uri'] === null) {
                    throw new ConfigurationException("{$providerKey}.authorization_uri is required when the provider has no issuer_uri to discover it from.");
                }
                if ($openId && $provider['jwk_set_uri'] === null) {
                    throw new ConfigurationException("{$providerKey}.jwk_set_uri is required: [{$id}] requests `openid`, so its id token must be verified against the provider's keys, and there is no issuer_uri to discover them from.");
                }
                if (! $openId && $provider['user_info_uri'] === null) {
                    throw new ConfigurationException("{$providerKey}.user_info_uri is required: [{$id}] does not request `openid`, so the userinfo endpoint is the only place a principal can be read from, and there is no issuer_uri to discover it from.");
                }
            }
        }

        return [
            'id' => $id,
            'key' => $key,
            'providerKey' => $providerKey,
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            'method' => $method,
            'grant' => $grant,
            'redirectUri' => $redirectUri,
            'scopes' => $scopes,
            'clientName' => $clientName,
            'pkce' => $pkce,
            'provider' => $provider,
        ];
    }

    /**
     * @param  array{id: string, key: string, providerKey: string, clientId: string, clientSecret: string, method: ClientAuthenticationMethod, grant: AuthorizationGrantType, redirectUri: string, scopes: list<string>, clientName: string, pkce: bool, provider: array<string, ?string>}  $plan
     */
    private function resolve(array $plan): ClientRegistration
    {
        $provider = $plan['provider'];
        $openId = in_array('openid', $plan['scopes'], true);
        $authorizationCode = $plan['grant'] === AuthorizationGrantType::AuthorizationCode;
        $issuer = $provider['issuer_uri'];

        $missing = $provider['token_uri'] === null
            || ($authorizationCode && $provider['authorization_uri'] === null)
            || ($authorizationCode && $openId && $provider['jwk_set_uri'] === null)
            || ($authorizationCode && ! $openId && $provider['user_info_uri'] === null);

        if ($issuer !== null && $missing) {
            $metadata = $this->discovery->metadata($issuer);
            $provider['authorization_uri'] ??= $metadata->authorizationEndpoint;
            $provider['token_uri'] ??= $metadata->tokenEndpoint;
            $provider['jwk_set_uri'] ??= $metadata->jwksUri;
            $provider['user_info_uri'] ??= $metadata->userInfoEndpoint;
            $provider['end_session_uri'] ??= $metadata->endSessionEndpoint;
        }

        $tokenUri = $provider['token_uri'] ?? throw new ConfigurationException("{$plan['providerKey']} has no token endpoint: the discovery document of the issuer does not name one and token_uri is not set.");
        $authorizationUri = $provider['authorization_uri'] ?? ($authorizationCode ? throw new ConfigurationException("{$plan['providerKey']} has no authorization endpoint: the discovery document of the issuer does not name one and authorization_uri is not set.") : '');
        if ($authorizationCode && $openId && $provider['jwk_set_uri'] === null) {
            throw new ConfigurationException("{$plan['providerKey']} has no JWKS: the discovery document of the issuer names no jwks_uri and jwk_set_uri is not set, so [{$plan['id']}]'s id token cannot be verified.");
        }
        if ($authorizationCode && ! $openId && $provider['user_info_uri'] === null) {
            throw new ConfigurationException("{$plan['providerKey']} has no userinfo endpoint: the discovery document of the issuer names none and user_info_uri is not set, so [{$plan['id']}] (no `openid` scope) has no source of a principal.");
        }

        return new ClientRegistration(
            registrationId: $plan['id'],
            clientId: $plan['clientId'],
            clientSecret: $plan['clientSecret'],
            clientAuthenticationMethod: $plan['method'],
            authorizationGrantType: $plan['grant'],
            redirectUri: $plan['redirectUri'],
            scopes: $plan['scopes'],
            clientName: $plan['clientName'],
            providerDetails: new ProviderDetails(
                authorizationUri: $authorizationUri,
                tokenUri: $tokenUri,
                jwkSetUri: $provider['jwk_set_uri'],
                userInfoUri: $provider['user_info_uri'],
                userNameAttribute: $provider['user_name_attribute'] ?? 'sub',
                issuerUri: $issuer,
                endSessionUri: $provider['end_session_uri'],
            ),
            pkce: $plan['pkce'],
        );
    }

    /**
     * The preset's block (all seven keys, null when the preset does not say) overlaid by the configured keys.
     *
     * @param  array<string, mixed>  $configured
     * @return array<string, ?string>
     */
    private static function providerBlock(?CommonOAuth2Provider $preset, array $configured, string $key): array
    {
        $block = array_fill_keys(self::PROVIDER_KEYS, null);
        foreach ($preset?->provider() ?? [] as $name => $value) {
            $block[$name] = $value;
        }
        foreach (self::PROVIDER_KEYS as $name) {
            $value = self::string($configured, $name, $key);
            if ($value !== null && $value !== '') {
                $block[$name] = $value;
            }
        }

        return $block;
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  list<string>  $known
     */
    private static function assertKnownKeys(array $block, array $known, string $key): void
    {
        foreach (array_keys($block) as $name) {
            if (! in_array((string) $name, $known, true)) {
                throw new ConfigurationException("{$key} carries an unknown setting [{$name}]; the settings are ".implode(', ', $known).'.');
            }
        }
    }

    /**
     * A scalar setting as the string it is, null when absent or null, and refused when it is anything else.
     *
     * @param  array<string, mixed>  $block
     */
    private static function string(array $block, string $name, string $key): ?string
    {
        $value = $block[$name] ?? null;
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        throw new ConfigurationException("{$key}.{$name} must be a string.");
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private static function bool(array $block, string $name, string $key): ?bool
    {
        $value = $block[$name] ?? null;
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        $normalised = is_string($value) || is_int($value) ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
        if ($normalised === null) {
            throw new ConfigurationException("{$key}.{$name} must be a boolean.");
        }

        return $normalised;
    }

    /**
     * `scope` as a list of non-empty strings, from a list or a space/comma separated string.
     *
     * @return list<string>
     */
    private static function scopes(mixed $value, string $key): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', trim($value)) ?: [];
        }
        if (! is_array($value)) {
            throw new ConfigurationException("{$key}.scope must be a list of scopes, or a space-separated string.");
        }
        $scopes = [];
        foreach ($value as $scope) {
            if (! is_string($scope)) {
                throw new ConfigurationException("{$key}.scope must be a list of strings.");
            }
            $scope = trim($scope);
            if ($scope !== '' && ! in_array($scope, $scopes, true)) {
                $scopes[] = $scope;
            }
        }

        return $scopes;
    }
}

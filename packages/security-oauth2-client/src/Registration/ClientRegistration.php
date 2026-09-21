<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Registration;

/**
 * One configured client (Spring's ClientRegistration): the credentials, how they are presented, the grant, the
 * redirect-uri TEMPLATE (`{baseUrl}` and `{registrationId}` expand per request, see RedirectUriTemplate), the
 * scopes, the display name the login page shows, the provider's endpoints, and whether PKCE is used.
 *
 * THE SECRET NEVER LEAVES THROUGH A DUMP. A registration is exactly the kind of object that ends up in a
 * `dd()`, a log context or an exception's context array, and every door those go through is masked: __debugInfo()
 * is what print_r/var_dump/dd read; jsonSerialize() is what json_encode and Monolog's NormalizerFormatter (the
 * LineFormatter and JsonFormatter behind Laravel's log channels, which check JsonSerializable before falling back
 * to the public properties) read for an object in a context array; and #[\SensitiveParameter] on the secret
 * replaces it with a SensitiveParameterValue in a stack trace that captured the constructor's arguments. What is
 * NOT covered, because PHP offers no hook for it, is var_export() and an `(array)` cast — both read the public
 * properties directly, so a registration must never be dumped through those. It is also why the authorized-client
 * store keeps the registration ID and never the registration: a session file or a cache entry must not carry a
 * secret either.
 */
final readonly class ClientRegistration implements \JsonSerializable
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public string $registrationId,
        public string $clientId,
        #[\SensitiveParameter]
        public string $clientSecret,
        public ClientAuthenticationMethod $clientAuthenticationMethod,
        public AuthorizationGrantType $authorizationGrantType,
        public string $redirectUri,
        public array $scopes,
        public string $clientName,
        public ProviderDetails $providerDetails,
        public bool $pkce = true,
    ) {}

    /** An OpenID Connect registration: `openid` among the scopes, so the token response must carry an id token. */
    public function usesOpenId(): bool
    {
        return in_array('openid', $this->scopes, true);
    }

    public function isPublicClient(): bool
    {
        return $this->clientAuthenticationMethod === ClientAuthenticationMethod::None;
    }

    /** PKCE is configurable for a confidential client and NOT optional for a public one, which has no other proof. */
    public function usesPkce(): bool
    {
        return $this->pkce || $this->isPublicClient();
    }

    /**
     * What print_r, var_dump and dd() show: the registration with its secret masked.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return $this->masked();
    }

    /**
     * What json_encode and a Monolog log context carry: the same masked view. The two hooks share one array so
     * a field added to the registration cannot be masked on one door and printed on the other.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->masked();
    }

    /**
     * @return array<string, mixed>
     */
    private function masked(): array
    {
        return [
            'registrationId' => $this->registrationId,
            'clientId' => $this->clientId,
            'clientSecret' => $this->clientSecret === '' ? '' : '***',
            'clientAuthenticationMethod' => $this->clientAuthenticationMethod->value,
            'authorizationGrantType' => $this->authorizationGrantType->value,
            'redirectUri' => $this->redirectUri,
            'scopes' => $this->scopes,
            'clientName' => $this->clientName,
            'providerDetails' => $this->providerDetails,
            'pkce' => $this->pkce,
        ];
    }
}

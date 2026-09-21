<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Registration;

use Symfony\Component\VarDumper\Caster\Caster;
use Symfony\Component\VarDumper\Cloner\Stub;

/**
 * One configured client (Spring's ClientRegistration): the credentials, how they are presented, the grant, the
 * redirect-uri TEMPLATE (`{baseUrl}` and `{registrationId}` expand per request, see RedirectUriTemplate), the
 * scopes, the display name the login page shows, the provider's endpoints, and whether PKCE is used.
 *
 * THE SECRET NEVER LEAVES THROUGH A DUMP. A registration is exactly the kind of object that ends up in a
 * `dd()`, a log context or an exception's context array, and each door those go through is masked by the hook
 * that door actually reads — they are not the same hook:
 *
 *   - print_r() and var_dump() read __debugInfo();
 *   - json_encode() and Monolog's NormalizerFormatter (the LineFormatter and JsonFormatter behind Laravel's log
 *     channels, which check JsonSerializable before falling back to the public properties) read jsonSerialize()
 *     for an object in a context array;
 *   - dd() and dump() — Laravel's are Symfony VarDumper's — do NOT stop at __debugInfo(): the cloner casts the
 *     object with `(array) $object` first and appends __debugInfo() as virtual entries, so the raw property would
 *     print right above the masked copy. That door is closed by castForDumper(), a VarDumper caster that
 *     Registration/casters.php registers in AbstractCloner::$defaultCasters when Composer's autoloader loads. It
 *     cannot be registered from a service provider: Laravel's FoundationServiceProvider builds the cloner behind
 *     dd() in its own register(), before any package provider runs, and a cloner copies the default casters when
 *     it is constructed — a caster added later never reaches it;
 *   - a stack trace that captured the constructor's arguments (zend.exception_ignore_args=0) shows a
 *     SensitiveParameterValue where the secret was, because of #[\SensitiveParameter].
 *
 * What is NOT covered, because PHP offers no hook for it, is var_export() and an `(array)` cast — both read the
 * public properties directly, so a registration must never be dumped through those. It is also why the
 * authorized-client store keeps the registration ID and never the registration: a session file or a cache entry
 * must not carry a secret either.
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
     * What print_r and var_dump show: the registration with its secret masked. (dd() and dump() read this too, but
     * only after the raw properties — see castForDumper().)
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return $this->masked();
    }

    /**
     * What json_encode and a Monolog log context carry: the same masked view. The two hooks share one array, and
     * the caster shares their mask, so a field added to the registration cannot be masked on one door and printed
     * on the other.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->masked();
    }

    /**
     * What dd() and dump() show — the VarDumper caster, with VarDumper's caster signature (object, the property
     * array Caster::castObject() built, stub, nested): the real properties with the secret masked, and without the
     * virtual copy of __debugInfo() the cloner appended under Caster::PREFIX_VIRTUAL, so a registration dumps once,
     * with `+clientSecret: "***"` where the raw value would have been. Registered by Registration/casters.php.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public static function castForDumper(self $registration, array $properties, Stub $stub, bool $isNested): array
    {
        foreach (array_keys($properties) as $key) {
            if (str_starts_with($key, Caster::PREFIX_VIRTUAL)) {
                unset($properties[$key]);
            }
        }
        $properties['clientSecret'] = $registration->maskedSecret();

        return $properties;
    }

    /**
     * @return array<string, mixed>
     */
    private function masked(): array
    {
        return [
            'registrationId' => $this->registrationId,
            'clientId' => $this->clientId,
            'clientSecret' => $this->maskedSecret(),
            'clientAuthenticationMethod' => $this->clientAuthenticationMethod->value,
            'authorizationGrantType' => $this->authorizationGrantType->value,
            'redirectUri' => $this->redirectUri,
            'scopes' => $this->scopes,
            'clientName' => $this->clientName,
            'providerDetails' => $this->providerDetails,
            'pkce' => $this->pkce,
        ];
    }

    /** One mask for every door: an empty secret (a public client) stays empty, anything else is `***`. */
    private function maskedSecret(): string
    {
        return $this->clientSecret === '' ? '' : '***';
    }
}

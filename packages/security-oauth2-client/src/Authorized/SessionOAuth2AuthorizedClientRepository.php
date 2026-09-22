<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Authorized;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Request;

/**
 * The authorized clients under one key of the Laravel session (Spring's HttpSessionOAuth2AuthorizedClientRepository),
 * as a map from registration id to an ENCRYPTED payload: each OAuth2AuthorizedClient is serialised and encrypted
 * with the application Encrypter (the app key, AES-256-CBC with an HMAC), so storage/framework/sessions, a
 * sessions table, Redis, or a debug page that dumps the session never shows an access token, a refresh token
 * or an id token in clear. The session driver's own `session.encrypt` is left to the application; this entry
 * is encrypted whatever that setting says.
 *
 * An entry that no longer decrypts — the application key was rotated — is dropped and read as absent: the
 * person signs in again, which is the honest outcome, rather than a DecryptException on every request that
 * touches the manager. A request with no session reads null and writes nothing, the shape every session
 * repository of firefly/security has.
 *
 * The Encrypter is resolved from the container ON USE: this is an eager singleton and a bare harness boots
 * without an application key (the SessionCsrf reasoning).
 */
final class SessionOAuth2AuthorizedClientRepository implements OAuth2AuthorizedClientRepository
{
    public const string KEY = 'firefly.security.oauth2.client.authorized_clients';

    public function __construct(private readonly Container $container) {}

    public function loadAuthorizedClient(string $clientRegistrationId, Request $request): ?OAuth2AuthorizedClient
    {
        $payload = $this->entries($request)[$clientRegistrationId] ?? null;
        if ($payload === null) {
            return null;
        }

        try {
            /** @var mixed $client */
            $client = $this->encrypter()->decrypt($payload);
        } catch (DecryptException) {
            $this->removeAuthorizedClient($clientRegistrationId, $request);

            return null;
        }

        return $client instanceof OAuth2AuthorizedClient ? $client : null;
    }

    public function saveAuthorizedClient(OAuth2AuthorizedClient $authorizedClient, Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $entries = $this->entries($request);
        $entries[$authorizedClient->registrationId] = $this->encrypter()->encrypt($authorizedClient);
        $request->session()->put(self::KEY, $entries);
    }

    public function removeAuthorizedClient(string $clientRegistrationId, Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $entries = $this->entries($request);
        unset($entries[$clientRegistrationId]);

        if ($entries === []) {
            $request->session()->forget(self::KEY);
        } else {
            $request->session()->put(self::KEY, $entries);
        }
    }

    /**
     * @return array<string, string>
     */
    private function entries(Request $request): array
    {
        if (! $request->hasSession()) {
            return [];
        }

        /** @var mixed $stored */
        $stored = $request->session()->get(self::KEY);
        if (! is_array($stored)) {
            return [];
        }

        $entries = [];
        foreach ($stored as $id => $payload) {
            if (is_string($id) && is_string($payload)) {
                $entries[$id] = $payload;
            }
        }

        return $entries;
    }

    private function encrypter(): Encrypter
    {
        /** @var Encrypter $encrypter */
        $encrypter = $this->container->make(Encrypter::class);

        return $encrypter;
    }
}

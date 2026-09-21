<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Authorized;

use Firefly\Security\OAuth2\Client\OAuth2ClientSettings;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\Date;

/**
 * Authorized clients in the Laravel cache (Spring's InMemory/JdbcOAuth2AuthorizedClientService, on the store
 * every LaraFly deployment already has), each entry serialised and ENCRYPTED with the application Encrypter —
 * a shared Redis is exactly the kind of place a token must not sit in clear — under a key derived from the
 * registration id and the principal name.
 *
 * THE TTL FOLLOWS THE TOKENS. A client that holds a refresh token, or whose access token carries no expiry, is
 * worth keeping for `authorized_client.cache_ttl` (a day): the manager can renew it. A client with an expiring
 * access token and nothing to renew it with is kept exactly until that token expires — and one already expired
 * is not stored at all — so the cache never answers a token the provider would refuse. An entry that no longer
 * decrypts is dropped and read as absent (a rotated key), as the session repository does.
 */
final class CacheOAuth2AuthorizedClientService implements OAuth2AuthorizedClientService
{
    public const string PREFIX = 'firefly.security.oauth2.client.authorized.';

    public function __construct(
        private readonly Cache $cache,
        private readonly Container $container,
        private readonly OAuth2ClientSettings $settings,
    ) {}

    public function loadAuthorizedClient(string $clientRegistrationId, string $principalName): ?OAuth2AuthorizedClient
    {
        $key = self::key($clientRegistrationId, $principalName);
        /** @var mixed $payload */
        $payload = $this->cache->get($key);
        if (! is_string($payload)) {
            return null;
        }

        try {
            /** @var mixed $client */
            $client = $this->encrypter()->decrypt($payload);
        } catch (DecryptException) {
            $this->cache->forget($key);

            return null;
        }

        return $client instanceof OAuth2AuthorizedClient ? $client : null;
    }

    public function saveAuthorizedClient(OAuth2AuthorizedClient $authorizedClient): void
    {
        $key = self::key($authorizedClient->registrationId, $authorizedClient->principalName);
        $ttl = $this->ttl($authorizedClient);
        if ($ttl <= 0) {
            $this->cache->forget($key);

            return;
        }

        $this->cache->put($key, $this->encrypter()->encrypt($authorizedClient), $ttl);
    }

    public function removeAuthorizedClient(string $clientRegistrationId, string $principalName): void
    {
        $this->cache->forget(self::key($clientRegistrationId, $principalName));
    }

    public static function key(string $registrationId, string $principalName): string
    {
        return self::PREFIX.sha1($registrationId.'|'.$principalName);
    }

    private function ttl(OAuth2AuthorizedClient $client): int
    {
        $expiresAt = $client->accessToken->expiresAt;
        if ($client->refreshToken !== null || $expiresAt === null) {
            return $this->settings->authorizedClientCacheTtlSeconds;
        }

        return $expiresAt - Date::now()->getTimestamp();
    }

    private function encrypter(): Encrypter
    {
        /** @var Encrypter $encrypter */
        $encrypter = $this->container->make(Encrypter::class);

        return $encrypter;
    }
}

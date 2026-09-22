<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Client\Authorized\CacheOAuth2AuthorizedClientService;
use Firefly\Security\OAuth2\Client\Authorized\OAuth2AuthorizedClient;
use Firefly\Security\OAuth2\Client\OAuth2ClientSettings;
use Firefly\Security\OAuth2\Client\Token\OAuth2AccessToken;
use Firefly\Security\OAuth2\Client\Token\OAuth2RefreshToken;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

/** A recording cache: what was put, and with which TTL. */
final class RecordingCacheRepository extends CacheRepository
{
    /** @var list<array{key: string, ttl: mixed}> */
    public array $puts = [];

    /**
     * @param  UnitEnum|array<array-key, mixed>|string  $key
     * @param  DateTimeInterface|DateInterval|int|null  $ttl
     */
    public function put($key, $value, $ttl = null): bool
    {
        // The service only ever puts a string key; anything else is recorded by its type so the assertion sees it.
        $this->puts[] = ['key' => is_string($key) ? $key : get_debug_type($key), 'ttl' => $ttl];

        return parent::put($key, $value, $ttl);
    }
}

function cacheService(RecordingCacheRepository $cache, int $ttl = 86400): CacheOAuth2AuthorizedClientService
{
    $container = new Container;
    $container->instance(EncrypterContract::class, new Encrypter('cccccccccccccccccccccccccccccccc', 'aes-256-cbc'));

    return new CacheOAuth2AuthorizedClientService($cache, $container, new OAuth2ClientSettings(authorizedClientCacheTtlSeconds: $ttl));
}

function cachedClient(?OAuth2RefreshToken $refresh, ?int $expiresAt, string $principal = 'ada'): OAuth2AuthorizedClient
{
    return new OAuth2AuthorizedClient('svc', $principal, new OAuth2AccessToken('the-access-token', 1000, $expiresAt, ['orders:read']), $refresh);
}

afterEach(fn () => Date::setTestNow());

it('keys by registration and principal, encrypts the entry, and hands it back whole', function () {
    $cache = new RecordingCacheRepository(new ArrayStore);
    $service = cacheService($cache);

    $service->saveAuthorizedClient(cachedClient(new OAuth2RefreshToken('rt', 1000), 5000));

    $raw = $cache->get(CacheOAuth2AuthorizedClientService::key('svc', 'ada'));
    expect($raw)->toBeString()->not->toContain('the-access-token')
        ->and($service->loadAuthorizedClient('svc', 'ada'))->toEqual(cachedClient(new OAuth2RefreshToken('rt', 1000), 5000))
        ->and($service->loadAuthorizedClient('svc', 'bob'))->toBeNull()
        ->and($service->loadAuthorizedClient('other', 'ada'))->toBeNull();

    $service->removeAuthorizedClient('svc', 'ada');
    expect($service->loadAuthorizedClient('svc', 'ada'))->toBeNull();
});

it('keeps a refreshable client for authorized_client.cache_ttl, a bare one until its access token expires, and none once it has', function () {
    Date::setTestNow(Carbon::createFromTimestamp(4000));
    $cache = new RecordingCacheRepository(new ArrayStore);
    $service = cacheService($cache, 1234);

    $service->saveAuthorizedClient(cachedClient(new OAuth2RefreshToken('rt', 1000), 5000, 'refreshable'));
    $service->saveAuthorizedClient(cachedClient(null, 5000, 'bare'));
    $service->saveAuthorizedClient(cachedClient(null, null, 'eternal'));
    $service->saveAuthorizedClient(cachedClient(null, 3999, 'expired'));

    expect(array_column($cache->puts, 'ttl'))->toBe([1234, 1000, 1234])
        ->and($service->loadAuthorizedClient('svc', 'expired'))->toBeNull()
        ->and($service->loadAuthorizedClient('svc', 'bare')?->principalName)->toBe('bare');
});

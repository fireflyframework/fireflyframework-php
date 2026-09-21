<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Http;

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\OAuth2\Client\Authorized\OAuth2AuthorizedClientManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;

/**
 * `Http::oauth2Client('{registrationId}')` — Spring's ServletOAuth2AuthorizedClientExchangeFilterFunction as a
 * Laravel Http macro: a PendingRequest from the application's Http factory carrying the bearer the
 * OAuth2AuthorizedClientManager hands out for the registration (and, optionally, for a named principal), so
 * `Http::oauth2Client('billing')->get($url)` fetches or refreshes the token, attaches it, and is otherwise
 * exactly `Http::get($url)`: faked by Http::fake(), traced by the observability wave's client middleware,
 * retried with ->retry(), and so on.
 *
 * REGISTERED AT PROVIDER register() TIME, not from a boot pass, so Larastan — which boots the discovered providers
 * when it analyses — types the call; behind `firefly.security.oauth2.client.http.macro` (default true). The
 * closure resolves the factory AND the manager from the container on every call: the factory is
 * the instance Http::fake() stubs (and the one the facade swaps), and the manager is a bean of the package
 * master, so calling the macro with the package off is a ConfigurationException naming the key — not a
 * container error deep inside Laravel. The closure does not read `$this` (the factory it is bound to) on
 * purpose: resolving the factory from the container answers the same instance and keeps PHPStan out of the
 * closure-binding question.
 */
final class OAuth2ClientHttpMacros
{
    public const string OAUTH2_CLIENT = 'oauth2Client';

    public static function register(Container $app): void
    {
        // NOT a static closure: Macroable::__call() binds the closure to the factory instance, and PHP warns
        // ("Cannot bind an instance to a static closure") on every call when asked to bind a static one.
        HttpFactory::macro(self::OAUTH2_CLIENT, function (string $registrationId, ?string $principalName = null) use ($app): PendingRequest {
            if (! $app->bound(OAuth2AuthorizedClientManager::class)) {
                throw new ConfigurationException("Http::oauth2Client('{$registrationId}') needs firefly.security.oauth2.client.enabled: the OAuth2AuthorizedClientManager is a bean of the package master.");
            }

            /** @var OAuth2AuthorizedClientManager $manager */
            $manager = $app->make(OAuth2AuthorizedClientManager::class);
            /** @var HttpFactory $http */
            $http = $app->make(HttpFactory::class);

            return $http->withToken($manager->authorize($registrationId, $principalName)->accessToken->tokenValue);
        });
    }
}

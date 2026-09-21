<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\User;

use Firefly\Security\OAuth2\Client\OAuth2ClientSettings;
use Firefly\Security\OAuth2\Client\Token\OAuth2AccessToken;
use Firefly\Security\OAuth2\Client\Token\OAuth2AuthenticationException;
use Firefly\Security\OAuth2\Client\Token\OAuth2Error;
use Firefly\Security\OAuth2\Client\Token\OAuth2ErrorCodes;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * GET user_info_uri with the access token as a bearer (OIDC Core §5.3; RFC 6750), `Accept: application/json`,
 * the package's two timeouts, over the Http factory resolved on use. Every failure — unreachable, a non-2xx,
 * a body that is not a JSON object — is `invalid_user_info_response`, naming the status and never the token.
 */
final class UserInfoClient
{
    public function __construct(
        private readonly Container $container,
        private readonly OAuth2ClientSettings $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function fetch(string $registrationId, string $userInfoUri, OAuth2AccessToken $accessToken): array
    {
        /** @var HttpFactory $http */
        $http = $this->container->make(HttpFactory::class);

        try {
            $response = $http->acceptJson()
                ->withToken($accessToken->tokenValue)
                ->connectTimeout($this->settings->connectTimeoutSeconds)
                ->timeout($this->settings->timeoutSeconds)
                ->get($userInfoUri);
        } catch (ConnectionException $e) {
            throw new OAuth2AuthenticationException($registrationId, new OAuth2Error(OAuth2ErrorCodes::INVALID_USER_INFO_RESPONSE, 'The userinfo endpoint could not be reached.'), $e);
        }

        if (! $response->successful()) {
            throw new OAuth2AuthenticationException($registrationId, new OAuth2Error(OAuth2ErrorCodes::INVALID_USER_INFO_RESPONSE, "The userinfo endpoint answered HTTP {$response->status()}."));
        }

        /** @var mixed $json */
        $json = $response->json();
        if (! is_array($json)) {
            throw new OAuth2AuthenticationException($registrationId, new OAuth2Error(OAuth2ErrorCodes::INVALID_USER_INFO_RESPONSE, 'The userinfo endpoint did not answer with a JSON object.'));
        }

        /** @var array<string, mixed> $json */
        return $json;
    }
}

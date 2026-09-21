<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Error;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * The two ways an OAuth2Error reaches a client: the JSON document of RFC 6749 §5.2 (token, introspection,
 * revocation, userinfo, registration) and the redirect of §4.1.2.1 (the authorization endpoint), the error
 * members appended to whatever query the registered redirect URI already carries and the client's `state` echoed
 * verbatim. Every JSON error is `Cache-Control: no-store` like every token response.
 */
final class OAuth2ErrorResponse
{
    /**
     * @param  array<string,string>  $headers
     */
    public static function json(OAuth2Error $error, int $status = 400, array $headers = []): JsonResponse
    {
        return new JsonResponse($error->toArray(), $status, $headers + ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    /**
     * @param  array<string,string>  $headers
     */
    public static function fromException(OAuth2AuthenticationException $exception, array $headers = []): JsonResponse
    {
        return self::json($exception->error(), $exception->status(), $headers);
    }

    public static function redirect(string $redirectUri, OAuth2Error $error, ?string $state): RedirectResponse
    {
        $params = $error->toArray();
        if ($state !== null && $state !== '') {
            $params['state'] = $state;
        }

        return new RedirectResponse(self::withQuery($redirectUri, $params));
    }

    /**
     * @param  array<string,string>  $params
     */
    public static function withQuery(string $uri, array $params): string
    {
        return $uri.(str_contains($uri, '?') ? '&' : '?').http_build_query($params, '', '&', PHP_QUERY_RFC1738);
    }
}

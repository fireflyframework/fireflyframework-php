<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Server\Error\OAuth2AuthenticationException;
use Firefly\Security\OAuth2\Server\Error\OAuth2Error;
use Firefly\Security\OAuth2\Server\Error\OAuth2ErrorCodes;
use Firefly\Security\OAuth2\Server\Error\OAuth2ErrorResponse;

it('renders the RFC 6749 §5.2 document with no-store headers', function () {
    $response = OAuth2ErrorResponse::json(new OAuth2Error(OAuth2ErrorCodes::INVALID_GRANT, 'The code has expired.'), 400);

    expect($response->getStatusCode())->toBe(400)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Pragma'))->toBe('no-cache')
        ->and(json_decode((string) $response->getContent(), true))->toBe(['error' => 'invalid_grant', 'error_description' => 'The code has expired.']);
});

it('carries the exception\'s status and extra headers, and omits an empty description', function () {
    $exception = new OAuth2AuthenticationException(new OAuth2Error(OAuth2ErrorCodes::INVALID_CLIENT), 401);
    $response = OAuth2ErrorResponse::fromException($exception, ['WWW-Authenticate' => 'Basic realm="oauth2"']);

    expect($exception->status())->toBe(401)
        ->and($exception->getMessage())->toBe('invalid_client')
        ->and($exception->errorCode())->toBe('OAUTH2_INVALID_CLIENT')
        ->and($response->getStatusCode())->toBe(401)
        ->and($response->headers->get('WWW-Authenticate'))->toBe('Basic realm="oauth2"')
        ->and(json_decode((string) $response->getContent(), true))->toBe(['error' => 'invalid_client']);
});

it('redirects with error, error_description and the echoed state, keeping an existing query string', function () {
    $response = OAuth2ErrorResponse::redirect('https://client.test/cb?keep=1', new OAuth2Error(OAuth2ErrorCodes::ACCESS_DENIED, 'The user refused.'), 'xyz');

    expect($response->getStatusCode())->toBe(302)
        ->and($response->getTargetUrl())->toBe('https://client.test/cb?keep=1&error=access_denied&error_description=The+user+refused.&state=xyz');

    expect(OAuth2ErrorResponse::redirect('https://client.test/cb', new OAuth2Error(OAuth2ErrorCodes::INVALID_SCOPE), null)->getTargetUrl())
        ->toBe('https://client.test/cb?error=invalid_scope');
});

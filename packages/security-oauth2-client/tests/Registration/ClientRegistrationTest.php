<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Client\Registration\AuthorizationGrantType;
use Firefly\Security\OAuth2\Client\Registration\ClientAuthenticationMethod;
use Firefly\Security\OAuth2\Client\Registration\ClientRegistration;
use Firefly\Security\OAuth2\Client\Registration\ProviderDetails;
use Firefly\Security\OAuth2\Client\Registration\RedirectUriTemplate;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\LogRecord;

/** @param list<string> $scopes */
function registration(array $scopes = ['openid', 'profile'], ClientAuthenticationMethod $method = ClientAuthenticationMethod::ClientSecretBasic, bool $pkce = true): ClientRegistration
{
    return new ClientRegistration(
        registrationId: 'okta',
        clientId: 'app',
        clientSecret: $method === ClientAuthenticationMethod::None ? '' : 'a-very-secret-value',
        clientAuthenticationMethod: $method,
        authorizationGrantType: AuthorizationGrantType::AuthorizationCode,
        redirectUri: '{baseUrl}/login/oauth2/code/{registrationId}',
        scopes: $scopes,
        clientName: 'Okta',
        providerDetails: new ProviderDetails('https://idp.example.com/authorize', 'https://idp.example.com/token', 'https://idp.example.com/jwks', null, 'sub', 'https://idp.example.com', 'https://idp.example.com/logout'),
        pkce: $pkce,
    );
}

it('knows whether it is an OpenID registration, a public client, and whether PKCE is used', function () {
    expect(registration()->usesOpenId())->toBeTrue()
        ->and(registration(['read:user'])->usesOpenId())->toBeFalse()
        ->and(registration()->isPublicClient())->toBeFalse()
        ->and(registration(method: ClientAuthenticationMethod::None)->isPublicClient())->toBeTrue()
        ->and(registration(pkce: false)->usesPkce())->toBeFalse()
        // A public client has no secret to prove itself with: PKCE is not optional for it, whatever `pkce` says.
        ->and(registration(method: ClientAuthenticationMethod::None, pkce: false)->usesPkce())->toBeTrue();
});

it('never prints its client secret through a dump', function () {
    $dump = print_r(registration(), true);
    $debug = registration()->__debugInfo();

    expect($dump)->not->toContain('a-very-secret-value')
        ->and($debug['clientSecret'])->toBe('***')
        ->and($debug['clientId'])->toBe('app')
        ->and(registration(method: ClientAuthenticationMethod::None)->__debugInfo()['clientSecret'])->toBe('');
});

it('never prints its client secret through json_encode or a log context', function () {
    // json_encode and Monolog's normalizer (Laravel's default log path for an object in a context array) both
    // read jsonSerialize() rather than __debugInfo(), so the mask has to cover that door as well.
    $json = json_encode(registration(), JSON_THROW_ON_ERROR);
    $record = new LogRecord(new DateTimeImmutable, 'app', Level::Warning, 'token exchange failed', ['registration' => registration()]);

    expect($json)->not->toContain('a-very-secret-value')
        ->and($json)->toContain('"clientSecret":"***"')
        ->and($json)->toContain('"clientId":"app"')
        ->and(registration()->jsonSerialize()['clientSecret'])->toBe('***')
        ->and(registration(method: ClientAuthenticationMethod::None)->jsonSerialize()['clientSecret'])->toBe('')
        ->and((new LineFormatter)->format($record))->not->toContain('a-very-secret-value')
        ->and((new LineFormatter)->format($record))->toContain('"clientSecret":"***"')
        ->and((new JsonFormatter)->format($record))->not->toContain('a-very-secret-value');
});

it('masks the client secret in a stack trace that captured the constructor arguments', function () {
    // A development php.ini keeps the arguments of every frame in an exception's trace, and a registration is
    // built from raw config — a TypeError on a mistyped key is a realistic way for the constructor call to end
    // up in a trace. #[\SensitiveParameter] replaces the secret with a SensitiveParameterValue in that frame.
    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        // @phpstan-ignore argument.type, new.resultUnused (the wrong type is the point: it makes the constructor frame appear in a trace; nothing is ever constructed)
        new ClientRegistration('okta', 'app', 'a-very-secret-value', ClientAuthenticationMethod::ClientSecretBasic, AuthorizationGrantType::AuthorizationCode, '{baseUrl}/cb', 'not-an-array', 'Okta', new ProviderDetails('https://idp.example.com/authorize', 'https://idp.example.com/token'));

        throw new LogicException('a mistyped scopes argument must be rejected, or this test proves nothing');
    } catch (TypeError $e) {
        $trace = $e->getTraceAsString();

        // getTraceAsString() truncates a string argument to 15 characters, so the negative check must look for
        // a prefix that would survive the truncation: 'a-very-secret-v...' is what an unmasked frame prints.
        expect($trace)->toContain("ClientRegistration->__construct('okta', 'app', Object(SensitiveParameterValue)")
            ->and($trace)->not->toContain('a-very-secret');
    } finally {
        if ($previous !== false) {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }
});

it('expands Spring\'s redirect-uri template from the application\'s base URL', function () {
    expect(RedirectUriTemplate::expand('{baseUrl}/login/oauth2/code/{registrationId}', 'https://app.example.com', 'okta'))->toBe('https://app.example.com/login/oauth2/code/okta')
        ->and(RedirectUriTemplate::expand('{baseUrl}/login/oauth2/code/{registrationId}', 'https://app.example.com/', 'okta'))->toBe('https://app.example.com/login/oauth2/code/okta')
        ->and(RedirectUriTemplate::expand('https://fixed.example.com/cb', 'https://app.example.com', 'okta'))->toBe('https://fixed.example.com/cb');
});

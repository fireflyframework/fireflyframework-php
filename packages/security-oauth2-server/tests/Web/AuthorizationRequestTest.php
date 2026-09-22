<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Server\Web\AuthorizationRequest;
use Illuminate\Http\Request;

it('reads every parameter of the query, splits scopes and prompts, and parses max_age', function () {
    $request = AuthorizationRequest::from(Request::create('/oauth2/authorize?response_type=code&client_id=web-app&redirect_uri=https%3A%2F%2Fa.test%2Fcb&scope=openid+profile++email&state=s1&code_challenge=abc&code_challenge_method=S256&nonce=n1&prompt=login+consent&max_age=300'));

    expect($request->clientId)->toBe('web-app')
        ->and($request->redirectUri)->toBe('https://a.test/cb')
        ->and($request->responseType)->toBe('code')
        ->and($request->scopes())->toBe(['openid', 'profile', 'email'])
        ->and($request->state)->toBe('s1')
        ->and($request->codeChallenge)->toBe('abc')
        ->and($request->codeChallengeMethod)->toBe('S256')
        ->and($request->nonce)->toBe('n1')
        ->and($request->prompt)->toBe(['login', 'consent'])
        ->and($request->prompts('login'))->toBeTrue()
        ->and($request->prompts('none'))->toBeFalse()
        ->and($request->maxAge)->toBe(300)
        ->and($request->maxAgeMalformed)->toBeFalse();
});

it('treats an absent parameter as null, an empty client_id as empty, and a non-numeric max_age as malformed', function () {
    $request = AuthorizationRequest::from(Request::create('/oauth2/authorize?max_age=soon'));

    expect($request->clientId)->toBe('')
        ->and($request->redirectUri)->toBeNull()
        ->and($request->scopes())->toBe([])
        ->and($request->prompt)->toBe([])
        ->and($request->maxAge)->toBeNull()
        ->and($request->maxAgeMalformed)->toBeTrue();
});

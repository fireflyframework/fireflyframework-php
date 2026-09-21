<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Web;

use Illuminate\Http\Request;

/**
 * Where an authorization request waits between the redirect and the callback (Spring's
 * AuthorizationRequestRepository). The shipped implementation is the session; an application may bind its own.
 * removeAuthorizationRequest() is the read the callback makes: it hands the request back AND forgets it, so a
 * state is single-use whatever the callback then decides.
 */
interface AuthorizationRequestRepository
{
    public function loadAuthorizationRequest(Request $request): ?OAuth2AuthorizationRequest;

    public function saveAuthorizationRequest(OAuth2AuthorizationRequest $authorizationRequest, Request $request): void;

    public function removeAuthorizationRequest(Request $request): ?OAuth2AuthorizationRequest;
}

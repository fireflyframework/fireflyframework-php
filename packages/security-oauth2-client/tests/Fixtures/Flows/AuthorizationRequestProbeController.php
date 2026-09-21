<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Tests\Fixtures\Flows;

use Firefly\Security\OAuth2\Client\Web\OAuth2AuthorizationRequest;
use Firefly\Security\OAuth2\Client\Web\SessionAuthorizationRequestRepository;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\RestController;
use Illuminate\Http\Request;

/** Reads the authorization request the redirect filter left in THIS session, so a flow can compare it with the redirect it got. */
#[RestController]
final class AuthorizationRequestProbeController
{
    /** @return array{state: ?string, nonce: ?string, codeVerifier: ?string, registrationId: ?string} */
    #[GetMapping('/open/authorization-request')]
    public function show(Request $request): array
    {
        $stored = $request->hasSession() ? $request->session()->get(SessionAuthorizationRequestRepository::KEY) : null;
        $stored = $stored instanceof OAuth2AuthorizationRequest ? $stored : null;

        return [
            'state' => $stored?->state,
            'nonce' => $stored?->nonce,
            'codeVerifier' => $stored?->codeVerifier,
            'registrationId' => $stored?->registrationId,
        ];
    }
}

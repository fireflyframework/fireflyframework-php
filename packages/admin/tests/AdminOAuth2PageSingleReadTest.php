<?php

declare(strict_types=1);

use Firefly\Actuator\Endpoint\ActuatorEndpoint;
use Firefly\Actuator\Endpoint\ActuatorRegistry;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Admin\Tests\Support\AdminCapstoneTestCase;

uses(AdminCapstoneTestCase::class);

/**
 * The OAuth2 page must read its endpoint ONCE per render.
 *
 * AdminEndpointReader deliberately does not memoize — it calls handle() again on every read, so a page that
 * calls payload() once per key pays for each one. That is free for an introspection endpoint answering from an
 * in-memory catalogue, and it is not free here: OAuth2ClientsEndpoint counts the authorizations alive for
 * every registered client, which the Eloquent service answers with one query per client. The page also loses
 * the endpoint's "one clock for the whole sweep" guarantee when it reads twice, because the two reads stamp
 * two different `now`s.
 *
 * A counting endpoint registered into the real registry is the only way to see it: the rendered HTML looks
 * identical whether the endpoint ran once or three times.
 */
it('reads the oauth2clients endpoint exactly once while rendering the OAuth2 page', function () {
    /** @var AdminCapstoneTestCase $this */
    $endpoint = new class implements ActuatorEndpoint
    {
        public int $calls = 0;

        public function endpointId(): string
        {
            return 'oauth2clients';
        }

        public function enabled(): bool
        {
            return true;
        }

        public function handle(EndpointRequest $request): EndpointResponse
        {
            $this->calls++;

            return EndpointResponse::json(['issuer' => 'https://issuer.test', 'clients' => [[
                'id' => 'storefront', 'clientId' => 'storefront', 'clientName' => 'Storefront',
                'authenticationMethods' => ['client_secret_basic'], 'grantTypes' => ['authorization_code'],
                'scopes' => ['openid'], 'redirectUris' => ['https://storefront.test/cb'], 'postLogoutRedirectUris' => [],
                'requireProofKey' => false, 'requiresProofKey' => true, 'requireAuthorizationConsent' => true,
                'accessTokenFormat' => 'self_contained', 'accessTokenTtl' => 300, 'activeAuthorizations' => 2,
            ]]]);
        }
    };

    /** @var ActuatorRegistry $registry */
    $registry = $this->app()->make(ActuatorRegistry::class);
    $registry->register($endpoint);

    $this->get('/firefly/oauth2')
        ->assertOk()
        ->assertSee('https://issuer.test')
        ->assertSee('storefront')
        // Rendered from the EFFECTIVE requirement, not from the client's own switch (which is off here).
        ->assertSee('PKCE');

    expect($endpoint->calls)->toBe(1);
});

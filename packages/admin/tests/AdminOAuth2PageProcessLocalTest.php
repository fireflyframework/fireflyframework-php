<?php

declare(strict_types=1);

use Firefly\Actuator\Endpoint\ActuatorEndpoint;
use Firefly\Actuator\Endpoint\ActuatorRegistry;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Admin\Tests\Support\AdminCapstoneTestCase;

uses(AdminCapstoneTestCase::class);

/**
 * An `oauth2clients` endpoint answering with the given storage model and two clients: one the store counts
 * nothing for, one it counts two authorizations for. Registered into the real registry, so the page is
 * rendered by the dashboard's own pipeline; the payload is stubbed because the two storage models are a
 * property of the authorization SERVICE, and booting a second worker to produce a genuine cross-process
 * count is not something a test can do.
 */
function oauth2ClientsStub(bool $processLocal): ActuatorEndpoint
{
    return new class($processLocal) implements ActuatorEndpoint
    {
        public function __construct(private readonly bool $processLocal) {}

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
            return EndpointResponse::json([
                'issuer' => 'https://issuer.test',
                'authorizations' => ['processLocal' => $this->processLocal],
                'clients' => [self::row('storefront', 0), self::row('reporting', 2)],
            ]);
        }

        /** @return array<string,mixed> */
        private static function row(string $clientId, int $active): array
        {
            return [
                'id' => $clientId, 'clientId' => $clientId, 'clientName' => ucfirst($clientId),
                'authenticationMethods' => ['client_secret_basic'], 'grantTypes' => ['authorization_code'],
                'scopes' => ['openid'], 'redirectUris' => ['https://'.$clientId.'.test/cb'], 'postLogoutRedirectUris' => [],
                'requireProofKey' => false, 'requiresProofKey' => true, 'requireAuthorizationConsent' => false,
                'accessTokenFormat' => 'self_contained', 'accessTokenTtl' => 300, 'activeAuthorizations' => $active,
            ];
        }
    };
}

/**
 * A `0` in the Active column is the cell most likely to be read as a fact, and on the default `memory`
 * driver it is the cell least able to be one: the counts come from the map of the single worker that
 * rendered the page, so a client with a hundred live authorizations across the pool still reports none here.
 * The page prints `—` for it — nothing counted — and names the key that makes the column server-wide. A
 * non-zero count is kept as it stands: that one is a floor the store can vouch for.
 */
it('marks a zero no per-process store can vouch for, and says whose authorizations the column counts', function () {
    /** @var AdminCapstoneTestCase $this */
    $this->app()->make(ActuatorRegistry::class)->register(oauth2ClientsStub(true));

    $this->get('/firefly/oauth2')
        ->assertOk()
        ->assertSee('Active counts this worker only')
        ->assertSee('authorizations.driver')
        ->assertSee('<td class="num">—</td>', false)
        ->assertSee('<td class="num">2</td>', false);
});

it('prints the counts as they stand, with no caveat, when a durable store answered', function () {
    /** @var AdminCapstoneTestCase $this */
    $this->app()->make(ActuatorRegistry::class)->register(oauth2ClientsStub(false));

    $this->get('/firefly/oauth2')
        ->assertOk()
        ->assertDontSee('Active counts this worker only')
        // `0` from oauth2_authorizations is the deployment's answer, not one worker's, so it is printed.
        ->assertSee('<td class="num">0</td>', false)
        ->assertSee('<td class="num">2</td>', false);
});

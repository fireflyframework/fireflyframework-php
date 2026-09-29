<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Support\Fixtures;

use Firefly\Actuator\Endpoint\ActuatorEndpoint;
use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;

/**
 * An `oauth2clients` endpoint answering with a fixed client list and a declared storage model.
 *
 * The payload is OAuth2ClientsEndpoint's own: `{issuer, authorizations: {processLocal}, clients: [...]}`, and
 * every client row carries both `requireProofKey` (the switch the client registered) and `requiresProofKey`
 * (what the endpoints actually enforce) so the page keeps being rendered from the second one.
 *
 * `processLocal` DEFAULTS TO TRUE here, which is the default `memory` driver's answer and therefore the
 * reading an operator is most likely to be given: the Active column then counts this worker's
 * authorizations only, a zero it cannot vouch for renders as `—`, and the page carries the paragraph naming
 * the key that makes the column server-wide. AdminOAuth2PageProcessLocalTest pins both models against its
 * own stub; this one exists so the LISTING — the colgroup, the header links, the pager — is asserted over
 * rows rather than over an empty state.
 */
final readonly class OAuth2ClientsEndpointStub implements ActuatorEndpoint
{
    /**
     * @param  list<array<string, mixed>>  $clients
     */
    public function __construct(
        private array $clients,
        private string $issuer = 'https://issuer.test',
        private bool $processLocal = true,
    ) {}

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
            'issuer' => $this->issuer,
            'authorizations' => ['processLocal' => $this->processLocal],
            'clients' => $this->clients,
        ]);
    }
}

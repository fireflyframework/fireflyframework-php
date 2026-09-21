<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Client\Tests\Support\OAuth2ClientCapstoneTestCase;
use Firefly\Security\Tests\Support\SecurityFlows;

/** `discovery.eager`: the issuer is discovered at boot, so the first request finds every endpoint already known. */
abstract class EagerDiscoveryCapstoneTestCase extends OAuth2ClientCapstoneTestCase
{
    protected function clientOverrides(): array
    {
        return ['firefly.security.oauth2.client.discovery.eager' => true];
    }
}

uses(EagerDiscoveryCapstoneTestCase::class, SecurityFlows::class);

it('discovers every issuer while booting, before any request', function () {
    /** @var EagerDiscoveryCapstoneTestCase $this */
    expect($this->idp->discoveryRequests)->toBe(1);

    $this->startLogin();

    expect($this->idp->discoveryRequests)->toBe(1);
});

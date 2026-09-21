<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Client\Tests\Support\OAuth2ClientCapstoneTestCase;
use Firefly\Security\Tests\Support\SecurityFlows;

/** A public client (`none`) cannot switch PKCE off: it has no secret, so the verifier is its only proof. */
abstract class PublicClientCapstoneTestCase extends OAuth2ClientCapstoneTestCase
{
    protected function registrationOverrides(): array
    {
        return ['client_secret' => '', 'client_authentication_method' => 'none', 'pkce' => false];
    }
}

uses(PublicClientCapstoneTestCase::class, SecurityFlows::class);

it('always sends a code_challenge for a public client, pkce setting or not', function () {
    /** @var PublicClientCapstoneTestCase $this */
    $query = $this->queryOf($this->startLogin());

    expect($query['code_challenge'] ?? null)->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($query['code_challenge_method'] ?? null)->toBe('S256');
});

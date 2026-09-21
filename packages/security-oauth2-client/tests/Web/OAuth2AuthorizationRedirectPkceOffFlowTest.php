<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Client\Tests\Support\OAuth2ClientCapstoneTestCase;
use Firefly\Security\Tests\Support\SecurityFlows;

/** A confidential client may switch PKCE off; the challenge then leaves the authorization request. */
abstract class PkceOffCapstoneTestCase extends OAuth2ClientCapstoneTestCase
{
    protected function registrationOverrides(): array
    {
        return ['pkce' => false];
    }
}

uses(PkceOffCapstoneTestCase::class, SecurityFlows::class);

it('sends no code_challenge when pkce is off for a confidential client', function () {
    /** @var PkceOffCapstoneTestCase $this */
    $query = $this->queryOf($this->startLogin());

    expect($query)->not->toHaveKey('code_challenge')
        ->not->toHaveKey('code_challenge_method')
        ->and($query['nonce'] ?? null)->not->toBeNull();
});

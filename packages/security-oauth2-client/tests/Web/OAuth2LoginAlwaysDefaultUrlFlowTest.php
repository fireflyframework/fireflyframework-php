<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Client\Tests\Support\OAuth2ClientCapstoneTestCase;
use Firefly\Security\Tests\Support\SecurityFlows;

/** `login.always_use_default_success_url`: the saved request is ignored and the default URL is where a login lands. */
abstract class AlwaysDefaultUrlCapstoneTestCase extends OAuth2ClientCapstoneTestCase
{
    protected function clientOverrides(): array
    {
        return [
            'firefly.security.oauth2.client.login.default_success_url' => '/home',
            'firefly.security.oauth2.client.login.always_use_default_success_url' => true,
        ];
    }
}

uses(AlwaysDefaultUrlCapstoneTestCase::class, SecurityFlows::class);

it('lands on the default URL even though a request was saved', function () {
    /** @var AlwaysDefaultUrlCapstoneTestCase $this */
    $refused = $this->get('/whoami', ['Accept' => 'text/html']);
    $refused->assertRedirect('/login');

    $this->forgetSession();
    $start = $this->followSession($refused)->get('/oauth2/authorization/fake');
    $start->assertRedirect();
    $callback = $this->follow($refused, $this->follow($refused, $start));

    $callback->assertRedirect('/home');
});

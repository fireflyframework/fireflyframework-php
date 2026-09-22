<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Server\Web\Consent\ConsentPage;
use Firefly\Security\OAuth2\Server\Web\Consent\ConsentPageModel;

it('renders the framework consent page: client, scopes as checked boxes, the token, the state, Allow and Deny, no script', function () {
    $html = ConsentPage::render(new ConsentPageModel(
        title: 'LaraFly',
        clientName: 'The <web> app',
        clientId: 'web-app',
        principalName: 'ada',
        scopes: [['scope' => 'openid', 'description' => ConsentPage::describe('openid'), 'approved' => false], ['scope' => 'orders:read', 'description' => ConsentPage::describe('orders:read'), 'approved' => true]],
        state: 'pending-1',
        action: '/oauth2/authorize',
        csrfToken: 'tok"en',
    ));

    expect($html)->toContain('<title>Allow access · LaraFly</title>')
        ->toContain('The &lt;web&gt; app')
        ->toContain('ada')
        ->toContain('action="/oauth2/authorize"')
        ->toContain('name="_token" value="tok&quot;en"')
        ->toContain('name="state" value="pending-1"')
        ->toContain('name="scope[]" value="openid" checked')
        ->toContain('name="scope[]" value="orders:read" checked')
        ->toContain('Sign you in')
        ->toContain('Access orders:read')
        ->toContain('already allowed')
        ->toContain('name="action" value="approve"')
        ->toContain('name="action" value="deny"')
        ->not->toContain('<script');
});

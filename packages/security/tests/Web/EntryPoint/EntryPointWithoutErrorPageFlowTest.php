<?php

declare(strict_types=1);

use Firefly\Security\Tests\Support\SecurityCapstoneTestCase;
use Firefly\Security\Tests\Support\SecurityFlows;

/**
 * The EntryPointFlowTest recipe with `firefly.web.error-page.enabled=false`: the documented way to prefer
 * Laravel's own error page over the framework's. For one release that branding choice also switched the
 * `auto` entry point's browser test off, so a person hitting a protected URL got the 401 instead of the login
 * page and nothing in the security docs said why. The flag must decide how a failure is DRAWN, never whether
 * a browser is asked to sign in.
 */
abstract class EntryPointWithoutErrorPageCapstoneTestCase extends SecurityCapstoneTestCase
{
    protected function securityOverrides(): array
    {
        return [
            'firefly.web.error-page.enabled' => false,
            'firefly.security.form_login.enabled' => true,
        ];
    }
}

uses(EntryPointWithoutErrorPageCapstoneTestCase::class, SecurityFlows::class);

it('still redirects a browser to the login page when the HTML error page is switched off', function () {
    /** @var EntryPointWithoutErrorPageCapstoneTestCase $this */
    $this->get('/home')->assertRedirect('/login');

    // A JSON client is still the 401 problem document, and an api/* URL is still a machine surface for a
    // copied browser header: json-paths describes the URL, and the URL did not change with the page off.
    $this->getJson('/api/orders')
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJson(['code' => 'AUTHENTICATION_FAILED']);

    $this->get('/api/orders', ['Accept' => 'text/html'])->assertStatus(401);

    expect($this->events->denials())->toBe([]);
});

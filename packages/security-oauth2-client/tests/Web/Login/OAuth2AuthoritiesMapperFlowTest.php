<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Client\Tests\Support\OAuth2ClientCapstoneTestCase;
use Firefly\Security\Tests\Support\SecurityFlows;

/** An application's GrantedAuthoritiesMapper bean, scanned as a #[Configuration], adds ROLE_* from the provider's groups. */
abstract class AuthoritiesMapperCapstoneTestCase extends OAuth2ClientCapstoneTestCase
{
    protected function fixturePaths(): array
    {
        return [...parent::fixturePaths(), 'Firefly\\Security\\OAuth2\\Client\\Tests\\Fixtures\\Mapper\\' => dirname(__DIR__, 2).'/Fixtures/Mapper'];
    }
}

uses(AuthoritiesMapperCapstoneTestCase::class, SecurityFlows::class);

it('maps the groups claim to roles on the Authentication, leaving the principal\'s own authorities as granted', function () {
    /** @var AuthoritiesMapperCapstoneTestCase $this */
    $this->idp->withUser(['groups' => ['engineering', 'oncall']]);
    $callback = $this->signInThroughProvider();
    $this->forgetSession();
    $client = $this->followSession($callback);

    $client->getJson('/whoami')->assertOk()->assertJson(['authorities' => ['OIDC_USER', 'SCOPE_openid', 'SCOPE_profile', 'SCOPE_email', 'ROLE_ENGINEERING', 'ROLE_ONCALL']]);
    $client->getJson('/api/engineers')->assertOk()->assertJson(['engineers' => true]);
    $client->getJson('/account')->assertOk()->assertJson(['groups' => ['engineering', 'oncall']]);

    expect($this->events->interactive()[0]->authentication->authorityStrings())->toContain('ROLE_ENGINEERING')
        ->and($this->events->denials())->toBe([]);
});

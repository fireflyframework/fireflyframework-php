<?php

declare(strict_types=1);

use Firefly\Actuator\ActuatorServiceProvider;
use Firefly\Actuator\ActuatorWiringProvider;
use Firefly\Admin\AdminServiceProvider;
use Firefly\Security\OAuth2\Server\Tests\Support\OAuth2ServerCapstoneTestCase;
use Firefly\Testing\Boot\FireflyBoot;
use Illuminate\Foundation\Application;

abstract class AdminOAuth2CapstoneTestCase extends OAuth2ServerCapstoneTestCase
{
    protected function fireflyProviders(): array
    {
        return [...parent::fireflyProviders(), ActuatorServiceProvider::class, ActuatorWiringProvider::class, AdminServiceProvider::class];
    }

    protected function serverOverrides(): array
    {
        return [
            'firefly.management.enabled' => true,
            'firefly.admin.enabled' => true,
            'firefly.security.http.rules' => [
                ['pattern' => 'open', 'access' => 'permitAll'],
                ['pattern' => 'open/*', 'access' => 'permitAll'],
                ['pattern' => 'api/*', 'access' => 'authenticated'],
                ['pattern' => 'firefly', 'access' => 'hasRole:ADMIN'],
                ['pattern' => 'firefly/*', 'access' => 'hasRole:ADMIN'],
                ['pattern' => '*', 'access' => 'authenticated'],
            ],
        ];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        parent::defineFireflyEnvironment($app);

        FireflyBoot::stubScheduledManifest($app);
    }
}

uses(AdminOAuth2CapstoneTestCase::class);

it('renders the Clients panel for an admin, read in-process, with the live authorization count and no secret', function () {
    /** @var AdminOAuth2CapstoneTestCase $this */
    $this->oauth2()->clientCredentials('svc', OAuth2ServerCapstoneTestCase::SVC_SECRET, 'orders:read')->assertOk();

    $this->signIn('root');
    $page = $this->get('/firefly/oauth2');

    $page->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertSee('OAuth2 clients')
        ->assertSee('http://localhost')
        ->assertSee('web-app')
        ->assertSee('The web application')
        ->assertSee('public-spa')
        ->assertSee('client_credentials')
        ->assertSee('orders:read')
        ->assertSee('client.create')
        // The one client-credentials token issued above is the one live authorization the svc row reports.
        ->assertSee('<td class="num">1</td>', false)
        // PKCE is reported as the endpoints ENFORCE it, not as the client registered it: public-spa carries
        // no `require_pkce` of its own, yet the stock server-wide default refuses its every authorization
        // request without a code_challenge. Its issuance cell is the one without `consent`, so this string
        // belongs to that row alone.
        ->assertSee('<td class="dim">self_contained · 300s · PKCE</td>', false)
        ->assertDontSee(OAuth2ServerCapstoneTestCase::WEB_APP_SECRET)
        ->assertDontSee(OAuth2ServerCapstoneTestCase::SVC_SECRET);

    // The JSON surface stays unexposed (health,info by default) while the dashboard renders the endpoint.
    $this->getJson('/actuator/oauth2clients')->assertStatus(404);
    $this->get('/firefly')->assertOk()->assertSee('OAuth2 clients');
});

<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Server\Tests\Support\OAuth2ServerCapstoneTestCase;
use Firefly\Security\Tests\Support\RecordingLogger;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\View;
use Psr\Log\LoggerInterface;

abstract class ConsentViewOverrideCapstoneTestCase extends OAuth2ServerCapstoneTestCase
{
    public RecordingLogger $logger;

    protected function serverOverrides(): array
    {
        return ['firefly.security.oauth2.server.consent.view' => 'firefly-oauth2-tests::custom-consent'];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        parent::defineFireflyEnvironment($app);

        $this->logger = new RecordingLogger;
        $app->instance(LoggerInterface::class, $this->logger);
    }
}

uses(ConsentViewOverrideCapstoneTestCase::class);

beforeEach(function () {
    View::addNamespace('firefly-oauth2-tests', dirname(__DIR__, 2).'/Fixtures/views');
});

it('renders the application\'s consent view with the same model, and the flow completes through it', function () {
    /** @var ConsentViewOverrideCapstoneTestCase $this */
    $this->signIn();
    $oauth2 = $this->oauth2();

    /** @var string $title the error page's title, which the consent page shares: `app.name` unless firefly.web.error-page.title says otherwise */
    $title = $this->app()->make('config')->get('app.name');

    $page = $oauth2->authorize('web-app', OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'openid profile');
    $page->assertOk()
        ->assertSee('OUR OWN CONSENT · '.$title.' · The web application · ada', escape: false)
        ->assertDontSee('<form class="panel form"', escape: false);

    $oauth2->approveConsent($page)->assertStatus(302);
    expect($this->logger->mentioning('consent view'))->toBe([]);
});

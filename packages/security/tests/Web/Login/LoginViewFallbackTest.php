<?php

declare(strict_types=1);

use Firefly\Security\Tests\Support\RecordingLogger;
use Firefly\Security\Tests\Support\SecurityCapstoneTestCase;
use Illuminate\Foundation\Application;
use Psr\Log\LoggerInterface;

/**
 * Form login on with `firefly.security.form_login.view` naming a view that is not there — the typo case —
 * through the real pipeline. The browser still gets a page to sign in with (the framework's), and the
 * application's logger gets a warning naming the view: this is the case that used to answer 200 with the
 * framework form and not a word anywhere, so a misspelt key silently replaced a branded page. The logger is
 * bound as Psr\Log\LoggerInterface before boot, exactly what LoginRouteRegistrar resolves for the action.
 */
abstract class LoginViewFallbackCapstoneTestCase extends SecurityCapstoneTestCase
{
    public RecordingLogger $logger;

    protected function securityOverrides(): array
    {
        return [
            'firefly.security.form_login.enabled' => true,
            'firefly.security.form_login.view' => 'auth.logon',
        ];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        parent::defineFireflyEnvironment($app);

        $this->logger = new RecordingLogger;
        $app->instance(LoggerInterface::class, $this->logger);
    }
}

uses(LoginViewFallbackCapstoneTestCase::class);

it('serves the framework page when the configured view does not exist, and warns the application\'s logger naming it', function () {
    /** @var LoginViewFallbackCapstoneTestCase $this */
    $page = $this->get('/login');

    $page->assertOk()
        ->assertSee('<form class="panel form"', escape: false)
        ->assertSee('name="_token"', escape: false);

    $warnings = $this->logger->mentioning('auth.logon');

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['level'])->toBe('warning')
        ->and($warnings[0]['message'])->toContain('does not exist')
        ->and($warnings[0]['context'])->toBe(['view' => 'auth.logon']);

    // The page it fell back to is a working one: the sign-in goes through.
    $this->forgetSession();
    $this->followSession($page)->post('/login', ['username' => 'ada', 'password' => 'secret', '_token' => $this->csrfTokenFrom($page)])->assertRedirect('/');
});

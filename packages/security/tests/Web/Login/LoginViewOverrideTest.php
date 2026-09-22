<?php

declare(strict_types=1);

use Firefly\Security\Tests\Support\RecordingLogger;
use Firefly\Security\Tests\Support\SecurityCapstoneTestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\View;
use Psr\Log\LoggerInterface;

/**
 * Form login on with `firefly.security.form_login.view` naming the application's own Blade view
 * (tests/Fixtures/views/custom-login), through the real pipeline: the framework's route renders the override
 * with the session token, the flow signs in through the override's form, and nothing is logged — the page the
 * configuration asked for is the page the browser got.
 */
abstract class LoginViewOverrideCapstoneTestCase extends SecurityCapstoneTestCase
{
    public RecordingLogger $logger;

    protected function securityOverrides(): array
    {
        return [
            'firefly.security.form_login.enabled' => true,
            'firefly.security.form_login.view' => 'firefly-security-tests::custom-login',
        ];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        parent::defineFireflyEnvironment($app);

        $this->logger = new RecordingLogger;
        $app->instance(LoggerInterface::class, $this->logger);
    }
}

uses(LoginViewOverrideCapstoneTestCase::class);

beforeEach(function () {
    View::addNamespace('firefly-security-tests', dirname(__DIR__, 2).'/Fixtures/views');
});

it('renders the application\'s view at {login_page} with the session token, the root-relative action and the query flags', function () {
    /** @var LoginViewOverrideCapstoneTestCase $this */
    $page = $this->get('/login');
    /** @var string $title the error page's title, which the login page shares: `app.name` unless firefly.web.error-page.title says otherwise */
    $title = $this->app()->make('config')->get('app.name');

    $page->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertSee('OUR OWN SIGN-IN · '.$title.' · /login · username · password · '.$this->csrfTokenFrom($page), escape: false)
        ->assertDontSee('<form class="panel form"', escape: false)
        ->assertDontSee('· WRONG', escape: false);

    $this->get('/login?error')->assertSee('· WRONG', escape: false);
    $this->get('/login?logout')->assertSee('· BYE', escape: false);

    expect($this->logger->mentioning('login view'))->toBe([]);
});

it('signs in through the application\'s view: the entry point sends the browser there, the filter answers the form it renders', function () {
    /** @var LoginViewOverrideCapstoneTestCase $this */
    $refused = $this->get('/home');
    $refused->assertRedirect('/login');

    $this->forgetSession();
    $page = $this->followSession($refused)->get('/login');
    $page->assertOk()->assertSee('OUR OWN SIGN-IN');

    $this->forgetSession();
    $login = $this->followSession($page)->post('/login', ['username' => 'ada', 'password' => 'secret', '_token' => $this->csrfTokenFrom($page)]);
    $login->assertRedirect('/home');

    $this->forgetSession();
    $this->followSession($login)->get('/home')->assertOk()->assertSee('Signed in as ada');
});

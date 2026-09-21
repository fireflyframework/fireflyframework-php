<?php

declare(strict_types=1);

use Firefly\Security\Core\Authentication;
use Firefly\Security\Tests\Support\SecurityCapstoneTestCase;
use Firefly\Security\Tests\Support\SecurityFlows;
use Firefly\Security\Web\Logout\LogoutSuccessHandler;
use Illuminate\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The LogoutSuccessHandler port through the real pipeline: bound before boot, it is asked BEFORE the session is
 * invalidated (it reads a session attribute to prove it) and its response replaces the default redirect; a null
 * hands back to `logout_success_url`. Everything else — CSRF, invalidation, the event — is unchanged.
 */
final class RecordingLogoutSuccessHandler implements LogoutSuccessHandler
{
    public ?string $sawInSession = null;

    public ?string $sawPrincipal = null;

    public bool $answer = true;

    public function onLogoutSuccess(Request $request, ?Authentication $authentication): ?Response
    {
        $marker = $request->session()->get('marker');
        $this->sawInSession = is_string($marker) ? $marker : null;
        $this->sawPrincipal = $authentication?->getName();

        return $this->answer ? new RedirectResponse('http://localhost/bye?from='.($authentication?->getName() ?? 'nobody')) : null;
    }
}

abstract class LogoutSuccessHandlerCapstoneTestCase extends SecurityCapstoneTestCase
{
    public RecordingLogoutSuccessHandler $handler;

    protected function securityOverrides(): array
    {
        return ['firefly.security.form_login.enabled' => true];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        parent::defineFireflyEnvironment($app);

        $this->handler = new RecordingLogoutSuccessHandler;
        $app->instance(LogoutSuccessHandler::class, $this->handler);
    }

    /** @return TestResponse<Response> */
    public function signInAsAda(): TestResponse
    {
        $page = $this->get('/login');
        $this->forgetSession();

        return $this->followSession($page)->post('/login', ['username' => 'ada', 'password' => 'secret', '_token' => $this->csrfTokenFrom($page)]);
    }

    /**
     * The session's CSRF token as /whoami reports it for the cookie the response carries — what a logout POST
     * from that session has to send.
     *
     * @param  TestResponse<Response>  $response
     */
    public function csrfTokenOf(TestResponse $response): string
    {
        $this->forgetSession();
        $token = $this->followSession($response)->get('/whoami')->json('csrf');

        return is_string($token) && $token !== '' ? $token : throw new RuntimeException('/whoami reported no csrf token.');
    }
}

uses(LogoutSuccessHandlerCapstoneTestCase::class, SecurityFlows::class);

it('asks the handler before the session dies and answers with its response', function () {
    /** @var LogoutSuccessHandlerCapstoneTestCase $this */
    $login = $this->signInAsAda();
    $this->forgetSession();
    $this->followSession($login)->get('/open/mark')->assertOk();

    $this->forgetSession();
    $home = $this->followSession($login)->get('/home');
    $home->assertOk();
    $token = $this->csrfTokenOf($login);

    $this->forgetSession();
    $logout = $this->followSession($login)->post('/logout', ['_token' => $token]);

    $logout->assertRedirect('http://localhost/bye?from=ada');
    expect($this->handler->sawInSession)->toBe('set')
        ->and($this->handler->sawPrincipal)->toBe('ada')
        ->and($this->events->logouts())->toHaveCount(1);

    $this->forgetSession();
    $this->followSession($logout)->getJson('/whoami')->assertStatus(401);
});

it('falls back to the configured redirect when the handler answers null', function () {
    /** @var LogoutSuccessHandlerCapstoneTestCase $this */
    $this->handler->answer = false;
    $login = $this->signInAsAda();
    $token = $this->csrfTokenOf($login);

    $this->forgetSession();
    $this->followSession($login)->post('/logout', ['_token' => $token])->assertRedirect('/login?logout');
});

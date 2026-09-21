<?php

declare(strict_types=1);

use Firefly\Security\Tests\Support\RecordingLogger;
use Firefly\Security\Web\Login\LoginPageAction;
use Firefly\Security\Web\Settings\FormLoginSettings;
use Firefly\Security\Web\Settings\RememberMeSettings;
use Firefly\Testing\FireflyTestCase;
use Firefly\Web\Error\ErrorPageSettings;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\View;

/**
 * An application overriding the login page with its own view (`firefly.security.form_login.view`).
 *
 * Tested against the REAL view factory and real Blade files on disk rather than a mock, for the reasons
 * firefly/web's ErrorPageOverrideTest gives: what is worth asserting is that the override receives the same
 * LoginPageModel the built-in page does — the root-relative action, the field names, the session's token,
 * the two query flags — and that a view which THROWS, or is not there at all, falls back to the built-in
 * page AND SAYS SO. A mock can be told to throw; only a real compile throws the way a drifted template does.
 *
 * The fallback is logged because it is otherwise invisible: a typo in `view` would replace the application's
 * branded page with the framework's, with a 200 and nothing else, and no test of the application would
 * notice. The warning names the view, so the log line is the one an operator greps for.
 */
uses(FireflyTestCase::class);

beforeEach(function () {
    View::addNamespace('firefly-security-tests', __DIR__.'/../../Fixtures/views');
});

$action = static function (?string $view, ?RecordingLogger $logger, bool $rememberMe = false, bool $views = true): LoginPageAction {
    return new LoginPageAction(
        new FormLoginSettings(enabled: true, loginProcessingUrl: '/auth/sign-in?next=1', usernameParameter: 'email', view: $view),
        new RememberMeSettings(enabled: $rememberMe, key: str_repeat('k', 32)),
        new ErrorPageSettings(title: 'Ledger'),
        $views ? app(ViewFactory::class) : null,
        $logger,
    );
};

/** A GET of the login page in a started session, as the session middleware hands it to the route. */
$request = static function (string $query = ''): Request {
    /** @var Store $session */
    $session = app('session.store');
    $session->start();

    $request = Request::create('http://app.example.com/login'.$query);
    $request->setLaravelSession($session);

    return $request;
};

it('renders the application\'s own view and hands it the login model the built-in page gets', function () use ($action, $request) {
    $logger = new RecordingLogger;
    $get = $request();
    $token = $get->session()->token();

    $html = (string) $action('firefly-security-tests::custom-login', $logger)($get)->getContent();

    expect($html)->toContain('OUR OWN SIGN-IN · Ledger · /auth/sign-in · email · password · '.$token)
        ->toContain('action="/auth/sign-in"')
        ->toContain('name="_token" value="'.$token.'"')
        ->not->toContain('<form class="panel form"')
        ->not->toContain('<!DOCTYPE html>')
        ->and($logger->records)->toBe([]);
});

it('hands the override the error and logout flags and the remember-me parameter', function () use ($action, $request) {
    expect((string) $action('firefly-security-tests::custom-login', null)($request('?error'))->getContent())->toContain('· WRONG')->not->toContain('· BYE')
        ->and((string) $action('firefly-security-tests::custom-login', null)($request('?logout'))->getContent())->toContain('· BYE')->not->toContain('· WRONG')
        ->and((string) $action('firefly-security-tests::custom-login', null, rememberMe: true)($request())->getContent())->toContain('· REMEMBER=remember-me');
});

it('falls back to the built-in page when the override throws, and logs a warning naming the view', function () use ($action, $request) {
    // An override is application code — a renamed layout, a template that has drifted from the model. A
    // person who cannot sign in cannot fix the view, so the built-in page is rendered; the operator, who
    // can, is told which view failed and why.
    $logger = new RecordingLogger;

    $html = (string) $action('firefly-security-tests::broken-login', $logger)($request())->getContent();

    expect($html)->toContain('<!DOCTYPE html>')
        ->toContain('<form class="panel form"')
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['level'])->toBe('warning')
        ->and($logger->records[0]['message'])->toContain('firefly-security-tests::broken-login')
        ->and($logger->records[0]['context']['view'])->toBe('firefly-security-tests::broken-login')
        ->and($logger->records[0]['context']['exception'])->toBeInstanceOf(Throwable::class);
});

it('falls back to the built-in page when the named view does not exist, and logs a warning naming the view', function () use ($action, $request) {
    // The typo case: `view` names nothing on disk. The 200 with the framework form is indistinguishable from
    // an unconfigured application; the warning is the only thing that says the configuration was ignored.
    $logger = new RecordingLogger;

    $html = (string) $action('firefly-security-tests::no-such-view', $logger)($request())->getContent();

    expect($html)->toContain('<!DOCTYPE html>')
        ->toContain('<form class="panel form"')
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['level'])->toBe('warning')
        ->and($logger->records[0]['message'])->toContain('firefly-security-tests::no-such-view')
        ->and($logger->records[0]['context'])->toBe(['view' => 'firefly-security-tests::no-such-view']);
});

it('logs a warning when a view is configured but the container has no view factory to render it with', function () use ($action, $request) {
    $logger = new RecordingLogger;

    $html = (string) $action('firefly-security-tests::custom-login', $logger, views: false)($request())->getContent();

    expect($html)->toContain('<form class="panel form"')
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['level'])->toBe('warning')
        ->and($logger->records[0]['message'])->toContain('firefly-security-tests::custom-login');
});

it('renders the built-in page without a word in the log when no view is configured', function () use ($action, $request) {
    $logger = new RecordingLogger;

    $html = (string) $action(null, $logger)($request())->getContent();

    expect($html)->toContain('<form class="panel form"')
        ->and($logger->records)->toBe([]);
});

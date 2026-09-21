<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Kernel\Exception\Security\AuthenticationException;
use Firefly\Security\Session\SavedRequest;
use Firefly\Security\Web\EntryPoint\BasicAuthenticationEntryPoint;
use Firefly\Security\Web\EntryPoint\DelegatingAuthenticationEntryPoint;
use Firefly\Security\Web\EntryPoint\LoginUrlAuthenticationEntryPoint;
use Firefly\Security\Web\EntryPoint\ProblemAuthenticationEntryPoint;
use Firefly\Security\Web\Settings\FormLoginSettings;
use Firefly\Security\Web\Settings\HttpBasicSettings;
use Firefly\Web\Error\ErrorPageRenderer;
use Firefly\Web\Error\ErrorPageSettings;
use Firefly\Web\Exception\ProblemDetailsRenderer;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

function entryPointBrowserRequest(string $uri, string $method = 'GET'): Request
{
    $request = Request::create($uri, $method, server: ['HTTP_ACCEPT' => 'text/html,application/xhtml+xml']);
    $store = new Store('firefly_session', new ArraySessionHandler(120));
    $store->start();
    $request->setLaravelSession($store);

    return $request;
}

function entryPointApiRequest(string $uri): Request
{
    return Request::create($uri, 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
}

function delegatingEntryPoint(string $mode, bool $formLogin, bool $basic, ?ErrorPageSettings $errorPage = null): DelegatingAuthenticationEntryPoint
{
    $errorPage ??= new ErrorPageSettings;
    $form = new FormLoginSettings(enabled: $formLogin);
    $http = new HttpBasicSettings(enabled: $basic, realm: 'Ledger');
    $pages = new ErrorPageRenderer($errorPage);
    $problems = new ProblemDetailsRenderer($errorPage);

    return new DelegatingAuthenticationEntryPoint(
        $mode,
        $form,
        $http,
        $pages,
        new LoginUrlAuthenticationEntryPoint($form),
        new BasicAuthenticationEntryPoint($http, $pages, $problems),
        new ProblemAuthenticationEntryPoint,
    );
}

it('sends a browser to the login page with the request saved when form login is on', function () {
    $request = entryPointBrowserRequest('http://localhost/reports?year=2026');

    $response = delegatingEntryPoint('auto', formLogin: true, basic: false)->commence($request, new AuthenticationException('Authentication is required.'));

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toBe('http://localhost/login')
        ->and(SavedRequest::consume($request->session()))->toBe('http://localhost/reports?year=2026');
});

it('does not save a POST, and never redirects a JSON client', function () {
    $post = entryPointBrowserRequest('http://localhost/reports', 'POST');
    delegatingEntryPoint('auto', formLogin: true, basic: false)->commence($post, new AuthenticationException('Authentication is required.'));

    expect(SavedRequest::consume($post->session()))->toBeNull()
        ->and(fn () => delegatingEntryPoint('auto', formLogin: true, basic: false)->commence(entryPointApiRequest('http://localhost/api/reports'), new AuthenticationException('Authentication is required.')))
        ->toThrow(AuthenticationException::class);
});

it('still sends a browser to the login page when the HTML error page is switched off', function () {
    // `firefly.web.error-page.enabled=false` is a branding choice ("fall back to Laravel's own error page");
    // it decides what a 401 LOOKS like, never whether a person is asked to sign in. The browser test is the
    // error page's negotiation minus its own feature flag, so form login stays usable with the page off.
    $off = new ErrorPageSettings(enabled: false);
    $exception = new AuthenticationException('Authentication is required.');

    $response = delegatingEntryPoint('auto', formLogin: true, basic: false, errorPage: $off)->commence(entryPointBrowserRequest('http://localhost/reports'), $exception);

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toBe('http://localhost/login')
        // A JSON client is still never redirected, and an api/* URL is still a machine surface whatever the
        // Accept header says: json-paths describes what the URL IS, which does not change with the page off.
        ->and(fn () => delegatingEntryPoint('auto', formLogin: true, basic: false, errorPage: $off)->commence(entryPointApiRequest('http://localhost/api/reports'), $exception))->toThrow(AuthenticationException::class)
        ->and(fn () => delegatingEntryPoint('auto', formLogin: true, basic: false, errorPage: $off)->commence(entryPointBrowserRequest('http://localhost/api/reports'), $exception))->toThrow(AuthenticationException::class);
});

it('challenges with WWW-Authenticate when basic is on and the client is not a browser, as a problem document', function () {
    $response = delegatingEntryPoint('auto', formLogin: false, basic: true)->commence(entryPointApiRequest('http://localhost/api/reports'), new AuthenticationException('Authentication is required.'));

    expect($response->getStatusCode())->toBe(401)
        ->and($response->headers->get('WWW-Authenticate'))->toBe('Basic realm="Ledger", charset="UTF-8"')
        ->and((string) $response->headers->get('Content-Type'))->toContain('application/problem+json')
        ->and((string) $response->getContent())->toContain('"code":"AUTHENTICATION_FAILED"');
});

it('forces one behaviour with login, challenge and problem', function () {
    $api = entryPointApiRequest('http://localhost/api/reports');
    $browser = entryPointBrowserRequest('http://localhost/reports');
    $exception = new AuthenticationException('Authentication is required.');

    expect(delegatingEntryPoint('login', formLogin: true, basic: true)->commence($api, $exception)->getStatusCode())->toBe(302)
        ->and(delegatingEntryPoint('challenge', formLogin: true, basic: true)->commence($browser, $exception)->headers->get('WWW-Authenticate'))->toStartWith('Basic realm=')
        ->and(fn () => delegatingEntryPoint('problem', formLogin: true, basic: true)->commence($browser, $exception))->toThrow(AuthenticationException::class);
});

it('falls back to the problem when nothing interactive is on, and validates the mode', function () {
    expect(fn () => delegatingEntryPoint('auto', formLogin: false, basic: false)->commence(entryPointBrowserRequest('http://localhost/reports'), new AuthenticationException('Authentication is required.')))->toThrow(AuthenticationException::class)
        ->and(DelegatingAuthenticationEntryPoint::modeFrom(new Config(new Repository([]))))->toBe('auto')
        ->and(fn () => DelegatingAuthenticationEntryPoint::modeFrom(new Config(new Repository(['firefly' => ['security' => ['http' => ['entry_point' => 'redirect']]]]))))->toThrow(ConfigurationException::class)
        ->and(fn () => DelegatingAuthenticationEntryPoint::modeFrom(new Config(new Repository(['firefly' => ['security' => ['http' => ['entry_point' => 'login']]]]))))->toThrow(ConfigurationException::class, 'entry_point')
        ->and(DelegatingAuthenticationEntryPoint::modeFrom(new Config(new Repository(['firefly' => ['security' => ['form_login' => ['enabled' => true], 'http' => ['entry_point' => 'login']]]]))))->toBe('login');
});

it('accepts the login mode for OAuth2 login alone, and negotiates a browser to the page for it', function () {
    $config = new Config(new Repository(['firefly' => ['security' => ['oauth2' => ['client' => ['login' => ['enabled' => true]]], 'http' => ['entry_point' => 'login']]]]));

    expect(DelegatingAuthenticationEntryPoint::modeFrom($config))->toBe('login');

    $form = new FormLoginSettings(enabled: false, pageEnabled: true);
    $http = new HttpBasicSettings;
    $pages = new ErrorPageRenderer(new ErrorPageSettings);
    $entryPoint = new DelegatingAuthenticationEntryPoint(
        DelegatingAuthenticationEntryPoint::AUTO,
        $form,
        $http,
        $pages,
        new LoginUrlAuthenticationEntryPoint($form),
        new BasicAuthenticationEntryPoint($http, $pages, new ProblemDetailsRenderer(new ErrorPageSettings)),
        new ProblemAuthenticationEntryPoint,
    );
    $request = entryPointBrowserRequest('http://localhost/admin');

    $response = $entryPoint->commence($request, new AuthenticationException('Authentication is required.'));

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toBe('http://localhost/login')
        ->and(SavedRequest::consume($request->session()))->toBe('http://localhost/admin');
});

<?php

declare(strict_types=1);

use Firefly\Security\Web\Login\LoginPageAction;
use Firefly\Security\Web\Login\LoginPageLink;
use Firefly\Security\Web\Login\LoginPageLinks;
use Firefly\Security\Web\Settings\FormLoginSettings;
use Firefly\Security\Web\Settings\RememberMeSettings;
use Firefly\Web\Error\ErrorPageSettings;
use Illuminate\Http\Request;

function loginPageAction(string $processingUrl = '/login'): LoginPageAction
{
    return new LoginPageAction(
        new FormLoginSettings(enabled: true, loginProcessingUrl: $processingUrl),
        new RememberMeSettings,
        new ErrorPageSettings(title: 'Ledger'),
    );
}

it('posts the form to a root-relative action, never to the scheme and host the request arrived with', function () {
    // What a TLS-terminating proxy the application does not trust hands PHP: a plain-http request for a page
    // the browser fetched over https. An absolute http:// action would be bounced to https as a GET.
    $html = (string) loginPageAction()(Request::create('http://app.example.com/login?error'))->getContent();

    expect($html)->toContain('action="/login"')
        ->not->toContain('action="http');
});

it('keeps the base path a front controller is served under, and only the path of the processing URL', function () {
    $request = Request::create('http://app.example.com/app/index.php/login', 'GET', [], [], [], [
        'SCRIPT_FILENAME' => '/var/www/app/index.php',
        'SCRIPT_NAME' => '/app/index.php',
    ]);

    expect($request->getBaseUrl())->toBe('/app/index.php')
        ->and((string) loginPageAction('/auth/sign-in?next=1')($request)->getContent())->toContain('action="/app/index.php/auth/sign-in"');
});

it('reads the error and logout flags off the query and carries an empty token without a session', function () {
    $html = (string) loginPageAction()(Request::create('http://app.example.com/login?logout'))->getContent();

    expect($html)->toContain('You have signed out.')
        ->toContain('name="_token" value=""');
});

it('hands the page the links a LoginPageLinks port answers, and no form when form login is off', function () {
    $links = new class implements LoginPageLinks
    {
        public function links(Request $request): array
        {
            return [new LoginPageLink('okta', 'Okta', $request->getBaseUrl().'/oauth2/authorization/okta')];
        }
    };
    $action = new LoginPageAction(
        new FormLoginSettings(enabled: false, pageEnabled: true),
        new RememberMeSettings,
        new ErrorPageSettings(title: 'Ledger'),
        null,
        null,
        $links,
    );

    $html = (string) $action(Request::create('http://app.example.com/login'))->getContent();

    expect($html)->toContain('Sign in with Okta')
        ->toContain('href="/oauth2/authorization/okta"')
        ->not->toContain('<form');
});

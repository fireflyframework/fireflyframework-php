<?php

declare(strict_types=1);

use Firefly\Security\Web\Login\LoginPage;
use Firefly\Security\Web\Login\LoginPageLink;
use Firefly\Security\Web\Login\LoginPageModel;

function loginModel(bool $error = false, bool $loggedOut = false, ?string $rememberMe = null): LoginPageModel
{
    return new LoginPageModel(
        title: 'Ledger <Co>',
        action: '/login',
        usernameParameter: 'email',
        passwordParameter: 'password',
        csrfToken: 'tok"en',
        error: $error,
        loggedOut: $loggedOut,
        rememberMeParameter: $rememberMe,
    );
}

it('renders a self-contained sign-in form in the framework design, with the session token and escaped values', function () {
    $html = LoginPage::render(loginModel());

    expect($html)->toStartWith('<!DOCTYPE html>')
        ->toContain('<title>Sign in · Ledger &lt;Co&gt;</title>')
        ->toContain('<form class="panel form" method="post" action="/login"')
        ->toContain('name="_token" value="tok&quot;en"')
        ->toContain('name="email"')
        ->toContain('autocomplete="username"')
        ->toContain('name="password"')
        ->toContain('autocomplete="current-password"')
        ->toContain('--brand:#e07a17')
        ->toContain('prefers-color-scheme: dark')
        ->not->toContain('remember-me')
        ->not->toContain('role="alert"')
        ->not->toContain('<script');
});

it('shows the error state, the signed-out notice and the remember-me checkbox when asked', function () {
    expect(LoginPage::render(loginModel(error: true)))->toContain('role="alert"')->toContain('Those credentials did not work.')
        ->and(LoginPage::render(loginModel(loggedOut: true)))->toContain('You have signed out.')
        ->and(LoginPage::render(loginModel(rememberMe: 'remember-me')))->toContain('type="checkbox" name="remember-me"');
});

it('lists every login-page link as a "Sign in with" button after the form, and draws no form for a links-only page', function () {
    $links = [new LoginPageLink('google', 'Google', '/oauth2/authorization/google'), new LoginPageLink('corp', 'Corp <SSO>', '/oauth2/authorization/corp')];

    $both = LoginPage::render(new LoginPageModel('Ledger', '/login', 'username', 'password', 'tok', false, false, null, true, $links));
    $linksOnly = LoginPage::render(new LoginPageModel('Ledger', '/login', 'username', 'password', 'tok', false, false, null, false, $links));

    expect($both)->toContain('<form class="panel form"')
        ->toContain('<a class="btn provider" href="/oauth2/authorization/google" data-provider="google">Sign in with Google</a>')
        ->toContain('Sign in with Corp &lt;SSO&gt;')
        ->toContain('<p class="divider"><span>or</span></p>')
        ->and(strpos($both, 'class="panel form"'))->toBeLessThan((int) strpos($both, 'class="panel providers"'))
        ->and($linksOnly)->not->toContain('<form')
        ->not->toContain('class="divider"')
        ->toContain('Sign in with Google')
        ->and(LoginPage::render(loginModel()))->not->toContain('providers');
});

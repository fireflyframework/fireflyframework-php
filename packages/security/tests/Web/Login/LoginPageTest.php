<?php

declare(strict_types=1);

use Firefly\Security\Web\Login\LoginPage;
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

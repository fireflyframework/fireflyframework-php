<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Web\Error\ErrorPageSettings;
use Illuminate\Config\Repository;

/**
 * The scheme guard on every operator-supplied href, and the reason it lives in the CONSTRUCTOR.
 *
 * The error page is a place a framework hands ITSELF whatever an operator put in an environment variable.
 * htmlspecialchars is the only thing between that string and a click, and it escapes quotes and brackets —
 * not schemes. `javascript:alert(document.cookie)` survives it intact, and an error page is a surface a
 * person reaches while already confused, on the origin that holds their session. Guarding at RENDER time
 * would be one forgotten call site away from the same hole, and guarding in fromConfig() alone would cover
 * only the construction path the framework itself uses — `firefly/security` builds these settings by hand
 * for its login page and a dozen tests construct them directly. Guarding in the constructor is what makes
 * the sentence true: the settings object cannot HOLD an unsafe URL, whoever built it and whichever surface
 * prints it.
 */
$settings = static fn (array $errorPage): ErrorPageSettings => ErrorPageSettings::fromConfig(
    new Config(new Repository(['firefly' => ['web' => ['error-page' => $errorPage]]])),
);

it('refuses a javascript: URL from configuration, in every slot that reaches an href', function () use ($settings) {
    $hostile = $settings([
        'home' => 'javascript:alert(document.cookie)',
        'sign-in' => 'JavaScript:alert(1)',
        'support' => "java\tscript:alert(1)",
    ]);

    expect($hostile->home)->toBe('')
        ->and($hostile->signIn)->toBe('')
        ->and($hostile->support)->toBe('');
});

it('refuses every other scheme that is not an ordinary web link', function () use ($settings) {
    foreach (['data:text/html;base64,PHN2Zy9vbmxvYWQ9YWxlcnQoMSk+', 'vbscript:msgbox(1)', 'file:///etc/passwd', 'mailto:ops@example.test', '//evil.test/phish', '\\\\evil.test\\share'] as $value) {
        expect($settings(['support' => $value])->support)->toBe('');
    }
});

/**
 * The two ways a value that begins with ONE slash still leaves the origin.
 *
 * A guard that refuses `//host/…` and stops there is refusing a spelling rather than a behaviour, and both
 * of the values it lets through land on a page a confused person is already standing on — which is what
 * makes the redirect credible. For a special scheme the URL standard's relative-slash state treats `\`
 * exactly like `/`, so a browser resolves `/\evil.test/phish` against this origin as
 * `https://evil.test/phish`; and before any parsing happens every ASCII tab, LF and CR is DELETED from the
 * input, so `/<TAB>/evil.test` is `//evil.test` by the time the resolver sees it. Each of these begins with
 * one slash and not two, and each is the phishing redirect the protocol-relative case is refused for.
 */
it('refuses every value a browser resolves off this origin, whichever separator the authority hides behind', function () use ($settings) {
    foreach (['/\\evil.test/phish', '/\\/evil.test', '/\\\\evil.test', "/\t/evil.test/phish", "/\n/evil.test/phish", "/\r/evil.test/phish", "//\tevil.test", 'https:/\\evil.test'] as $value) {
        expect($settings(['support' => $value])->support)->toBe('');
    }
});

it('keeps an absolute path and an http(s) URL, which is the whole legitimate vocabulary', function () use ($settings) {
    $good = $settings([
        'home' => '/',
        'sign-in' => '/login?next=%2Forders',
        'support' => 'https://support.example.test/tickets/new',
    ]);

    expect($good->home)->toBe('/')
        ->and($good->signIn)->toBe('/login?next=%2Forders')
        ->and($good->support)->toBe('https://support.example.test/tickets/new')
        ->and($settings(['support' => 'HTTP://legacy.example.test/help'])->support)->toBe('HTTP://legacy.example.test/help');
});

it('defaults home to the site root, offers no sign-in or support link, and renders the action row', function () use ($settings) {
    $defaults = $settings([]);

    expect($defaults->home)->toBe('/')
        ->and($defaults->signIn)->toBe('')
        ->and($defaults->support)->toBe('')
        ->and($defaults->actions)->toBeTrue();
});

it('lets an operator switch the action row off entirely', function () use ($settings) {
    expect($settings(['actions' => false])->actions)->toBeFalse();
});

/**
 * The guard holds for a settings object nobody read out of configuration.
 *
 * `firefly/security` builds an ErrorPageSettings by hand for the login page, and this package's own render
 * tests construct one directly. A guard that ran only in fromConfig() would have covered exactly one call
 * site while the class advertised an invariant over all of them; the constructor is the seam every path
 * goes through, so this is the assertion that the advertisement is honest.
 */
it('guards a settings object built by hand, not only one read from configuration', function () {
    $hostile = new ErrorPageSettings(
        home: 'javascript:alert(document.cookie)',
        signIn: '/\\evil.test/login',
        support: "java\tscript:alert(1)",
    );

    expect($hostile->home)->toBe('')
        ->and($hostile->signIn)->toBe('')
        ->and($hostile->support)->toBe('');

    $good = new ErrorPageSettings(home: '/dashboard', support: 'https://support.example.test');

    expect($good->home)->toBe('/dashboard')
        ->and($good->support)->toBe('https://support.example.test')
        ->and((new ErrorPageSettings)->home)->toBe('/');
});

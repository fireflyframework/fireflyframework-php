<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Web\Error\ErrorPageSettings;
use Illuminate\Config\Repository;

/**
 * The scheme guard on every operator-supplied href, and the reason it lives in fromConfig().
 *
 * The error page is a place a framework hands ITSELF whatever an operator put in an environment variable.
 * htmlspecialchars is the only thing between that string and a click, and it escapes quotes and brackets —
 * not schemes. `javascript:alert(document.cookie)` survives it intact, and an error page is a surface a
 * person reaches while already confused, on the origin that holds their session. Guarding at RENDER time
 * would be one forgotten call site away from the same hole; guarding here means the settings object cannot
 * HOLD an unsafe URL, whoever built it and whichever surface prints it.
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

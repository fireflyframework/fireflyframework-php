<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\Access\HttpSecurity;
use Illuminate\Support\Str;

it('builds an ordered rule list with expressions', function () {
    $rules = HttpSecurity::create()
        ->requestMatcher('api/public/*')->permitAll()
        ->requestMatcher('api/admin/*')->hasRole('ADMIN')
        ->anyRequest()->authenticated()
        ->build();

    expect($rules)->toHaveCount(3)
        ->and($rules[0]->pattern)->toBe('api/public/*')
        ->and($rules[0]->expression)->toBe('permitAll()')
        ->and($rules[1]->expression)->toBe("hasRole('ADMIN')")
        ->and($rules[2]->pattern)->toBe('*')
        ->and($rules[2]->expression)->toBe('isAuthenticated()');
});

it('builds from config', function () {
    $rules = HttpSecurity::fromConfig([
        ['pattern' => 'api/*', 'access' => 'hasAuthority:orders:read'],
        ['pattern' => '*', 'access' => 'denyAll'],
    ])->build();

    expect($rules[0]->expression)->toBe("hasAuthority('orders:read')")
        ->and($rules[1]->expression)->toBe('denyAll()');
});

it('rejects a quote-injection value in hasRole()', function () {
    HttpSecurity::create()->requestMatcher('x')->hasRole("A') or permitAll() or hasRole('B");
})->throws(ConfigurationException::class);

it('rejects a quote-injection value in hasAuthority()', function () {
    HttpSecurity::create()->requestMatcher('x')->hasAuthority("A') or permitAll() or hasAuthority('B");
})->throws(ConfigurationException::class);

it('rejects a quote-injection role value via fromConfig()', function () {
    HttpSecurity::fromConfig([
        ['pattern' => 'api/*', 'access' => "hasRole:A') or permitAll() or hasRole('B"],
    ]);
})->throws(ConfigurationException::class);

it('rejects a quote-injection authority value via fromConfig()', function () {
    HttpSecurity::fromConfig([
        ['pattern' => 'api/*', 'access' => "hasAuthority:A') or permitAll() or hasAuthority('B"],
    ]);
})->throws(ConfigurationException::class);

it('accepts hasScope in the builder and the hasScope: access spec', function () {
    $rules = HttpSecurity::fromConfig([['pattern' => 'api/orders', 'access' => 'hasScope:orders:read']])->build();

    expect($rules[0]->expression)->toBe("hasScope('orders:read')")
        ->and(HttpSecurity::create()->requestMatcher('api/*')->hasScope('profile')->build()[0]->expression)->toBe("hasScope('profile')");
});

it('normalises a leading slash off every pattern so a rule written /api/* is the rule api/*', function () {
    // `$request->path()` never carries a leading slash, so an un-normalised `/api/*` would be a DEAD rule:
    // Str::is('/api/*', 'api/orders') is false. A dead rule is indistinguishable from an absent one, and
    // deny-by-default then refuses the very path the operator opened.
    $rules = HttpSecurity::fromConfig([
        ['pattern' => '/api/public/*', 'access' => 'permitAll'],
        ['pattern' => '/', 'access' => 'permitAll'],
    ])->build();

    expect($rules[0]->pattern)->toBe('api/public/*')
        ->and(Str::is($rules[0]->pattern, 'api/public/ping'))->toBeTrue()
        // Root KEEPS its slash: Laravel answers '/' for the root path, never '', so normalising it away
        // would turn the one pattern that must match into one that never can.
        ->and($rules[1]->pattern)->toBe('/')
        ->and(Str::is($rules[1]->pattern, '/'))->toBeTrue()
        ->and(HttpSecurity::create()->requestMatcher('/admin/*')->hasRole('ADMIN')->build()[0]->pattern)->toBe('admin/*');
});

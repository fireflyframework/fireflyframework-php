<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\Access\HttpSecurity;

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

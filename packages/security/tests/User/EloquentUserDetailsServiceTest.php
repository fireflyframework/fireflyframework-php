<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\Authentication\Exception\UsernameNotFoundException;
use Firefly\Security\Tests\Fixtures\Users\Account;
use Firefly\Security\User\EloquentUserDetailsService;
use Firefly\Security\User\User;
use Firefly\Security\User\UserStoreSettings;
use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

uses(FireflyDatabaseTestCase::class);

beforeEach(function () {
    Schema::create('accounts', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('email')->unique();
        $table->string('password')->nullable();
        $table->boolean('enabled')->default(true);
        $table->boolean('locked')->default(false);
        $table->json('authorities')->nullable();
    });

    Account::query()->create(['email' => 'ada@example.com', 'password' => '{noop}secret', 'authorities' => ['ROLE_USER', 'orders:read']]);
    Account::query()->create(['email' => 'off@example.com', 'password' => '{noop}secret', 'enabled' => false, 'locked' => true, 'authorities' => null]);
});

function eloquentUsers(string $enabled = 'enabled', string $locked = 'locked', string $authorities = 'authorities'): EloquentUserDetailsService
{
    return new EloquentUserDetailsService(new UserStoreSettings('eloquent', [], Account::class, 'email', 'password', $enabled, $locked, $authorities));
}

it('maps a row to a User value object, never the model, and reads a JSON authorities column', function () {
    $user = eloquentUsers()->loadUserByUsername('ada@example.com');

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->getUsername())->toBe('ada@example.com')
        ->and($user->getPassword())->toBe('{noop}secret')
        ->and(array_map(static fn ($a) => $a->getAuthority(), $user->getAuthorities()))->toBe(['ROLE_USER', 'orders:read'])
        ->and($user->isEnabled())->toBeTrue()
        ->and($user->isAccountNonLocked())->toBeTrue();

    $off = eloquentUsers()->loadUserByUsername('off@example.com');

    expect($off->isEnabled())->toBeFalse()
        ->and($off->isAccountNonLocked())->toBeFalse()
        ->and($off->getAuthorities())->toBe([]);
});

it('treats an empty enabled/locked column as always enabled and never locked', function () {
    $off = eloquentUsers(enabled: '', locked: '')->loadUserByUsername('off@example.com');

    expect($off->isEnabled())->toBeTrue()->and($off->isAccountNonLocked())->toBeTrue();
});

it('throws UsernameNotFoundException for an unknown username', function () {
    expect(fn () => eloquentUsers()->loadUserByUsername('ghost@example.com'))->toThrow(UsernameNotFoundException::class);
});

it('reads a NULL password column as the empty string, which no encoder matches, and refuses a column that is not text', function () {
    Account::query()->create(['email' => 'social@example.com', 'password' => null, 'authorities' => ['ROLE_USER']]);

    expect(eloquentUsers()->loadUserByUsername('social@example.com')->getPassword())->toBe('');

    // The authorities column is an array cast: pointing password_column at it is a configuration mistake,
    // named as such rather than surfacing as a TypeError from the User constructor.
    $misconfigured = new EloquentUserDetailsService(new UserStoreSettings('eloquent', [], Account::class, 'email', 'authorities', '', '', 'authorities'));

    expect(fn () => $misconfigured->loadUserByUsername('ada@example.com'))->toThrow(ConfigurationException::class, 'authorities');
});

<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\Authentication\Exception\UsernameNotFoundException;
use Firefly\Security\Tests\Fixtures\Users\Account;
use Firefly\Security\Tests\Fixtures\Users\RawAccount;
use Firefly\Security\Tests\Fixtures\Users\Role;
use Firefly\Security\User\EloquentUserDetailsService;
use Firefly\Security\User\User;
use Firefly\Security\User\UserDetails;
use Firefly\Security\User\UserStoreSettings;
use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        $table->unsignedInteger('role_id')->nullable();
    });
    Schema::create('roles', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('name');
    });
    Schema::create('account_role', function (Blueprint $table): void {
        $table->unsignedInteger('account_id');
        $table->unsignedInteger('role_id');
    });

    Account::query()->create(['email' => 'ada@example.com', 'password' => '{noop}secret', 'authorities' => ['ROLE_USER', 'orders:read']]);
    Account::query()->create(['email' => 'off@example.com', 'password' => '{noop}secret', 'enabled' => false, 'locked' => true, 'authorities' => null]);
});

function eloquentUsers(string $enabled = 'enabled', string $locked = 'locked', string $authorities = 'authorities', string $model = Account::class, string $password = 'password'): EloquentUserDetailsService
{
    return new EloquentUserDetailsService(new UserStoreSettings('eloquent', [], $model, 'email', $password, $enabled, $locked, $authorities));
}

/** @return list<string> */
function authorityNames(UserDetails $user): array
{
    return array_map(static fn ($a) => $a->getAuthority(), $user->getAuthorities());
}

it('maps a row to a User value object, never the model, and reads a JSON authorities column', function () {
    $user = eloquentUsers()->loadUserByUsername('ada@example.com');

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->getUsername())->toBe('ada@example.com')
        ->and($user->getPassword())->toBe('{noop}secret')
        ->and(authorityNames($user))->toBe(['ROLE_USER', 'orders:read'])
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
    $misconfigured = eloquentUsers(enabled: '', locked: '', password: 'authorities');

    expect(fn () => $misconfigured->loadUserByUsername('ada@example.com'))->toThrow(ConfigurationException::class, 'authorities');
});

it('decodes a JSON authorities column the model does not cast, dropping what is not a non-empty string', function () {
    // RawAccount reads the same table with no casts, so `authorities` arrives as the JSON text the database
    // holds — the shape of any model that never declared an `array` cast — and the driver decodes it itself.
    DB::table('accounts')->insert(['email' => 'raw@example.com', 'password' => '{noop}secret', 'authorities' => '["orders:read","",42,"x"]']);

    $user = eloquentUsers(model: RawAccount::class)->loadUserByUsername('raw@example.com');

    expect($user->getPassword())->toBe('{noop}secret')
        ->and(authorityNames($user))->toBe(['orders:read', 'x'])
        // Without the bool casts the flags come back as the integers SQLite stores; (bool) reads them right.
        ->and($user->isEnabled())->toBeTrue()
        ->and($user->isAccountNonLocked())->toBeTrue();

    // Malformed JSON, or JSON that is not a list, is no authority at all rather than a decode error.
    DB::table('accounts')->where('email', 'raw@example.com')->update(['authorities' => '{not json']);

    expect(eloquentUsers(model: RawAccount::class)->loadUserByUsername('raw@example.com')->getAuthorities())->toBe([]);
});

it('plucks `relation.attribute` from every related model — roles.name over a roles table — and from a single related model', function () {
    $admin = Role::query()->create(['name' => 'ROLE_ADMIN']);
    $user = Role::query()->create(['name' => 'ROLE_USER']);
    $blank = Role::query()->create(['name' => '']);
    /** @var Account $ada */
    $ada = Account::query()->where('email', 'ada@example.com')->firstOrFail();
    $ada->roles()->attach([$admin->getKey(), $user->getKey(), $blank->getKey()]);
    $ada->forceFill(['role_id' => $admin->getKey()])->save();

    // The documented form: a belongsToMany reached through the relation method, the attribute plucked from
    // each related model, a blank name dropped, and the JSON column on the row itself never consulted.
    $viaRoles = eloquentUsers(authorities: 'roles.name')->loadUserByUsername('ada@example.com');

    expect(authorityNames($viaRoles))->toBe(['ROLE_ADMIN', 'ROLE_USER']);

    // A relation to one model (a belongsTo `role`) contributes that model's attribute rather than nothing.
    expect(authorityNames(eloquentUsers(authorities: 'primaryRole.name')->loadUserByUsername('ada@example.com')))->toBe(['ROLE_ADMIN']);

    // No related rows is no authority, not an error: the relation exists, it is merely empty.
    expect(eloquentUsers(authorities: 'roles.name')->loadUserByUsername('off@example.com')->getAuthorities())->toBe([]);
});

it('refuses a configured column the row does not carry instead of reading it as NULL — a locked_column typo must not unlock every account', function () {
    // Model::getAttribute() answers null for an unknown key, and `! (bool) null` is true: with the check
    // this pins, `is_locked` typed where the column is `locked` would disable account lockout for every
    // row with no error anywhere. The refusal names the setting and the column.
    expect(fn () => eloquentUsers(locked: 'is_locked')->loadUserByUsername('off@example.com'))
        ->toThrow(ConfigurationException::class, 'firefly.security.users.locked_column names `is_locked`');

    // The same principle in every other position: enabled, authorities (a silent loss of every role
    // otherwise) and password (an empty string otherwise). username_column is the WHERE of the lookup, so
    // an absent one never reaches the mapping: a query error on MySQL/Postgres, no row at all on SQLite
    // (which reads an unknown quoted identifier as a string literal) — closed either way.
    expect(fn () => eloquentUsers(enabled: 'is_enabled')->loadUserByUsername('ada@example.com'))
        ->toThrow(ConfigurationException::class, 'enabled_column names `is_enabled`');
    expect(fn () => eloquentUsers(authorities: 'permissions')->loadUserByUsername('ada@example.com'))
        ->toThrow(ConfigurationException::class, 'authorities names `permissions`');
    expect(fn () => eloquentUsers(password: 'password_hash')->loadUserByUsername('ada@example.com'))
        ->toThrow(ConfigurationException::class, 'password_column names `password_hash`');

    // A relation the model does not define is refused the same way; a typo there would be `[]` for everyone.
    expect(fn () => eloquentUsers(authorities: 'rolez.name')->loadUserByUsername('ada@example.com'))
        ->toThrow(ConfigurationException::class, 'authorities names `rolez`');

    // The negative control: a column the row DOES carry, holding NULL, is still read as NULL — the social
    // sign-in account with no password set keeps its documented empty-string password.
    Account::query()->create(['email' => 'social@example.com', 'password' => null]);

    expect(eloquentUsers()->loadUserByUsername('social@example.com')->getPassword())->toBe('');
});

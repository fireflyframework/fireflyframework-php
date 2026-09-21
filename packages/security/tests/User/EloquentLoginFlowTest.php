<?php

declare(strict_types=1);

use Firefly\Security\Event\AuthenticationFailureBadCredentialsEvent;
use Firefly\Security\Tests\Fixtures\Users\Account;
use Firefly\Security\Tests\Support\SecurityCapstoneTestCase;
use Firefly\Security\Tests\Support\SecurityFlows;
use Illuminate\Database\Schema\Blueprint;

/**
 * The eloquent driver through the real pipeline: form login on, the user store an `accounts` table read by
 * the Account model, and the same flows the memory store answers — a sign-in that lands the principal in the
 * session, and an unknown email refused exactly like a wrong password.
 */
abstract class EloquentUsersCapstoneTestCase extends SecurityCapstoneTestCase
{
    protected function securityOverrides(): array
    {
        return [
            'firefly.security.form_login.enabled' => true,
            'firefly.security.users' => [
                'driver' => 'eloquent',
                'model' => Account::class,
                'enabled_column' => 'enabled',
                'locked_column' => 'locked',
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema('accounts', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('enabled')->default(true);
            $table->boolean('locked')->default(false);
            $table->json('authorities')->nullable();
        });

        Account::query()->create(['email' => 'ada@example.com', 'password' => '{bcrypt}'.password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]), 'authorities' => ['ROLE_USER']]);
    }
}

uses(EloquentUsersCapstoneTestCase::class, SecurityFlows::class);

it('signs in against the accounts table and reports an unknown email exactly like a wrong password', function () {
    /** @var EloquentUsersCapstoneTestCase $this */
    $page = $this->get('/login');
    $this->forgetSession();
    $login = $this->followSession($page)->post('/login', ['username' => 'ada@example.com', 'password' => 'secret', '_token' => $this->csrfTokenFrom($page)]);
    $login->assertRedirect('/');

    $this->forgetSession();
    $this->followSession($login)->getJson('/whoami')->assertJson(['name' => 'ada@example.com', 'authorities' => ['ROLE_USER']]);

    $this->forgetSession();
    $again = $this->followSession($login)->get('/login');
    $this->followSession($again)->post('/login', ['username' => 'ghost@example.com', 'password' => 'secret', '_token' => $this->csrfTokenFrom($again)])->assertRedirect('/login?error');

    expect($this->events->failures())->toHaveCount(1)
        ->and($this->events->failures()[0])->toBeInstanceOf(AuthenticationFailureBadCredentialsEvent::class);
});

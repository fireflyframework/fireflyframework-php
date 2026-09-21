<?php

declare(strict_types=1);

use Firefly\Security\Tests\Fixtures\Users\StockUser;
use Firefly\Security\Tests\Support\SecurityCapstoneTestCase;
use Firefly\Security\Tests\Support\SecurityFlows;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * The eloquent driver over Laravel's STOCK `users` table through the real pipeline — the configuration the
 * skeleton's `'model' => App\Models\User::class` example implies: `id`, `name`, `email`, `password` and
 * nothing else. The three optional columns are opted out with the empty string, `authorities` included:
 * without that last opt-out the default names an `authorities` column this table does not have, and the
 * absent-column refusal would turn every sign-in into a 500. This is the file that proves it does not.
 */
abstract class EloquentStockUsersCapstoneTestCase extends SecurityCapstoneTestCase
{
    protected function securityOverrides(): array
    {
        return [
            'firefly.security.form_login.enabled' => true,
            'firefly.security.users' => [
                'driver' => 'eloquent',
                'model' => StockUser::class,
                'enabled_column' => '',
                'locked_column' => '',
                'authorities' => '',
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
        });

        DB::table('users')->insert(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => '{bcrypt}'.password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4])]);
    }
}

uses(EloquentStockUsersCapstoneTestCase::class, SecurityFlows::class);

it('signs in against a stock users table with no authorities column, and the principal holds none', function () {
    /** @var EloquentStockUsersCapstoneTestCase $this */
    $page = $this->get('/login');
    $this->forgetSession();
    $login = $this->followSession($page)->post('/login', ['username' => 'ada@example.com', 'password' => 'secret', '_token' => $this->csrfTokenFrom($page)]);
    $login->assertRedirect('/');

    // The `csrf` member is the session's own token — the one the login page renders for the same cookie.
    $this->forgetSession();
    $token = $this->csrfTokenFrom($this->followSession($login)->get('/login'));

    $this->forgetSession();
    $this->followSession($login)->getJson('/whoami')->assertOk()->assertExactJson(['name' => 'ada@example.com', 'authorities' => [], 'authenticated' => true, 'csrf' => $token]);

    expect($this->events->failures())->toBe([]);
});

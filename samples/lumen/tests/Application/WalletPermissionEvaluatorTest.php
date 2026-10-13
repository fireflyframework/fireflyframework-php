<?php

declare(strict_types=1);

namespace Lumen\Tests\Application;

use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Illuminate\Container\Container;
use Lumen\Application\Command\Deposit;
use Lumen\Application\Command\Transfer;
use Lumen\Application\Command\Withdraw;
use Lumen\Domain\Currency;
use Lumen\Domain\Wallet;
use Lumen\Infrastructure\WalletRepository;
use Lumen\Security\WalletPermissionEvaluator;
use stdClass;

/**
 * The evaluator on its own, over an in-memory WalletRepository and an empty container: no boot, no bus. The loan
 * branch needs the event store, so LoanCommandsTest drives it through the real bean instead.
 */
function lumenWalletPermissionEvaluator(): WalletPermissionEvaluator
{
    $wallets = new class implements WalletRepository
    {
        /** @var array<string, Wallet> */
        private array $byId = [];

        public function save(Wallet $wallet): Wallet
        {
            $key = $wallet->getKey();
            $this->byId[is_string($key) ? $key : ''] = $wallet;

            return $wallet;
        }

        public function findById(string $id): ?Wallet
        {
            return $this->byId[$id] ?? null;
        }

        public function findByOwnerId(string $ownerId): array
        {
            return array_values(array_filter($this->byId, fn (Wallet $wallet): bool => $wallet->ownerId() === $ownerId));
        }
    };
    $wallets->save(Wallet::open('wlt-alice', 'alice', Currency::EUR));
    $wallets->save(Wallet::open('wlt-bob', 'bob', Currency::EUR));

    return new WalletPermissionEvaluator($wallets, new Container);
}

function lumenWalletOwner(string $name): Authentication
{
    return Authentication::authenticated($name, $name, [new SimpleGrantedAuthority('ROLE_WALLET_OWNER')]);
}

it('grants debit on a withdraw to the owner of the wallet', function () {
    expect(lumenWalletPermissionEvaluator()->hasPermission(lumenWalletOwner('alice'), new Withdraw('wlt-alice', 100), 'debit'))
        ->toBeTrue();
})->group('lumen');

it('refuses debit on a withdraw to a principal who does not own the wallet', function () {
    expect(lumenWalletPermissionEvaluator()->hasPermission(lumenWalletOwner('mallory'), new Withdraw('wlt-alice', 100), 'debit'))
        ->toBeFalse();
})->group('lumen');

it('grants debit on a transfer to the owner of the source wallet', function () {
    expect(lumenWalletPermissionEvaluator()->hasPermission(lumenWalletOwner('alice'), new Transfer('wlt-alice', 'wlt-bob', 100), 'debit'))
        ->toBeTrue();
})->group('lumen');

it('refuses debit on a transfer to the owner of the destination wallet: a transfer debits its source', function () {
    expect(lumenWalletPermissionEvaluator()->hasPermission(lumenWalletOwner('bob'), new Transfer('wlt-alice', 'wlt-bob', 100), 'debit'))
        ->toBeFalse();
})->group('lumen');

it('refuses debit on a transfer to a principal who owns neither wallet', function () {
    expect(lumenWalletPermissionEvaluator()->hasPermission(lumenWalletOwner('mallory'), new Transfer('wlt-alice', 'wlt-bob', 100), 'debit'))
        ->toBeFalse();
})->group('lumen');

it('refuses debit on a transfer out of a wallet that does not exist', function () {
    expect(lumenWalletPermissionEvaluator()->hasPermission(lumenWalletOwner('alice'), new Transfer('wlt-nope', 'wlt-alice', 100), 'debit'))
        ->toBeFalse();
})->group('lumen');

it('refuses debit on a wallet that does not exist', function () {
    expect(lumenWalletPermissionEvaluator()->hasPermission(lumenWalletOwner('alice'), new Withdraw('wlt-nope', 100), 'debit'))
        ->toBeFalse();
})->group('lumen');

it('refuses a principal that is not authenticated, even when its name is the owner', function () {
    $anonymous = Authentication::unauthenticated('alice', 'alice', null);

    expect(lumenWalletPermissionEvaluator()->hasPermission($anonymous, new Withdraw('wlt-alice', 100), 'debit'))
        ->toBeFalse();
})->group('lumen');

it('refuses a permission it does not know, even to the owner', function (string $permission) {
    expect(lumenWalletPermissionEvaluator()->hasPermission(lumenWalletOwner('alice'), new Withdraw('wlt-alice', 100), $permission))
        ->toBeFalse();
})->with(['read', 'Debit', 'withdraw', ''])->group('lumen');

it('refuses a target it does not know, even to the owner', function (mixed $target) {
    expect(lumenWalletPermissionEvaluator()->hasPermission(lumenWalletOwner('alice'), $target, 'debit'))
        ->toBeFalse();
})->with([
    'a deposit' => fn () => new Deposit('wlt-alice', 100),
    'a bare wallet id' => 'wlt-alice',
    'an object' => fn () => new stdClass,
    'null' => null,
])->group('lumen');

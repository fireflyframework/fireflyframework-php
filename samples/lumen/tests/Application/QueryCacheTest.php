<?php

declare(strict_types=1);

namespace Lumen\Tests\Application;

use Firefly\Cqrs\Command\CommandBus;
use Firefly\Cqrs\Query\QueryBus;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Illuminate\Support\Facades\DB;
use Lumen\Application\Command\Deposit;
use Lumen\Application\Command\OpenWallet;
use Lumen\Application\Command\Withdraw;
use Lumen\Application\Query\GetBalance;
use Lumen\Domain\Currency;

uses(LumenQueryCacheTestCase::class);

afterEach(fn () => SecurityContextHolder::clearContext());

it('answers a repeated balance query from the cache until the next deposit evicts it', function () {
    /** @var LumenQueryCacheTestCase $this */
    /** @var CommandBus $commands */
    $commands = $this->fireflyContext()->get(CommandBus::class);
    /** @var QueryBus $queries */
    $queries = $this->fireflyContext()->get(QueryBus::class);

    /** @var string $id */
    $id = $commands->send(new OpenWallet('owner-cache', Currency::EUR));
    $commands->send(new Deposit($id, 1000));

    expect($queries->ask(new GetBalance($id)))->toBe(1000);

    // Changed behind the bus's back: the cached answer does not see it.
    DB::table('wallets')->where('id', $id)->update(['balance_minor' => 5000]);
    expect($queries->ask(new GetBalance($id)))->toBe(1000);

    // A committed deposit raises FundsDeposited, whose #[InvalidatesQuery] evicts exactly this wallet's balance.
    $commands->send(new Deposit($id, 500));
    expect($queries->ask(new GetBalance($id)))->toBe(5500);
})->group('lumen');

it('evicts only the balance of the wallet that changed', function () {
    /** @var LumenQueryCacheTestCase $this */
    /** @var CommandBus $commands */
    $commands = $this->fireflyContext()->get(CommandBus::class);
    /** @var QueryBus $queries */
    $queries = $this->fireflyContext()->get(QueryBus::class);

    /** @var string $a */
    $a = $commands->send(new OpenWallet('owner-a', Currency::EUR));
    /** @var string $b */
    $b = $commands->send(new OpenWallet('owner-b', Currency::EUR));
    $commands->send(new Deposit($a, 100));
    $commands->send(new Deposit($b, 200));
    expect($queries->ask(new GetBalance($a)))->toBe(100)
        ->and($queries->ask(new GetBalance($b)))->toBe(200);

    DB::table('wallets')->whereIn('id', [$a, $b])->update(['balance_minor' => 9000]);
    $commands->send(new Deposit($a, 1));

    expect($queries->ask(new GetBalance($a)))->toBe(9001)
        ->and($queries->ask(new GetBalance($b)))->toBe(200);
})->group('lumen');

it('evicts the balance when funds are withdrawn', function () {
    /** @var LumenQueryCacheTestCase $this */
    SecurityContextHolder::setContext(new SecurityContext(
        Authentication::authenticated('owner-w', 'owner-w', [new SimpleGrantedAuthority('ROLE_WALLET_OWNER')])
    ));
    /** @var CommandBus $commands */
    $commands = $this->fireflyContext()->get(CommandBus::class);
    /** @var QueryBus $queries */
    $queries = $this->fireflyContext()->get(QueryBus::class);

    /** @var string $id */
    $id = $commands->send(new OpenWallet('owner-w', Currency::EUR));
    $commands->send(new Deposit($id, 3000));
    expect($queries->ask(new GetBalance($id)))->toBe(3000);

    $commands->send(new Withdraw($id, 1000));

    expect($queries->ask(new GetBalance($id)))->toBe(2000);
})->group('lumen');

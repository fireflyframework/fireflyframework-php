<?php

declare(strict_types=1);

namespace Lumen\Tests\Application;

use Firefly\Cqrs\Command\CommandBus;
use Firefly\Cqrs\Exception\CommandProcessingException;
use Firefly\Cqrs\Query\QueryBus;
use Firefly\Kernel\Exception\Security\AuthorizationException;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Lumen\Application\Command\Deposit;
use Lumen\Application\Command\OpenWallet;
use Lumen\Application\Command\Transfer;
use Lumen\Application\Command\Withdraw;
use Lumen\Application\Query\GetBalance;
use Lumen\Domain\Currency;
use Lumen\Tests\LumenTestCase;

uses(LumenTestCase::class);

// Mirror SecurityAuthorizerTest: never let a principal set inside one test leak into the next. PHP always runs
// finally/afterEach, so even a throwing test (the deny case below) still clears the request-scoped holder.
afterEach(fn () => SecurityContextHolder::clearContext());

it('transfers atomically: money is conserved across debit + credit', function () {
    /** @var LumenTestCase $this */
    /** @var CommandBus $commands */
    $commands = $this->fireflyContext()->get(CommandBus::class);
    /** @var QueryBus $queries */
    $queries = $this->fireflyContext()->get(QueryBus::class);

    /** @var string $src */
    $src = $commands->send(new OpenWallet('owner-A', Currency::EUR));
    /** @var string $dst */
    $dst = $commands->send(new OpenWallet('owner-B', Currency::EUR));
    $commands->send(new Deposit($src, 10000));

    $commands->send(new Transfer($src, $dst, 4000));

    // Debit + credit committed as one unit of work: the 10000 that left nowhere reappears split 6000/4000.
    expect($queries->ask(new GetBalance($src)))->toBe(6000);
    expect($queries->ask(new GetBalance($dst)))->toBe(4000);
})->group('lumen');

it('rolls the whole transfer back when the credit leg fails (money cannot vanish)', function () {
    /** @var LumenTestCase $this */
    /** @var CommandBus $commands */
    $commands = $this->fireflyContext()->get(CommandBus::class);
    /** @var QueryBus $queries */
    $queries = $this->fireflyContext()->get(QueryBus::class);

    // Destination in a DIFFERENT currency: the debited EUR amount cannot be credited into a USD wallet, so the
    // credit leg throws currency-mismatch AFTER the debit already ran -> the whole #[Transactional] tx rolls back.
    /** @var string $src */
    $src = $commands->send(new OpenWallet('owner-A', Currency::EUR));
    /** @var string $dst */
    $dst = $commands->send(new OpenWallet('owner-B', Currency::USD));
    $commands->send(new Deposit($src, 10000));

    expect(fn () => $commands->send(new Transfer($src, $dst, 4000)))
        ->toThrow(CommandProcessingException::class);

    // Load-bearing, non-tautological proof that money cannot vanish: the source debit was ROLLED BACK (still 10000,
    // not 6000) and the destination never received anything (still 0). No value was created or destroyed.
    expect($queries->ask(new GetBalance($src)))->toBe(10000);
    expect($queries->ask(new GetBalance($dst)))->toBe(0);
})->group('lumen');

it('enforces #[PreAuthorize] on withdraw: denies without the owner role, allows with it', function () {
    /** @var LumenTestCase $this */
    /** @var CommandBus $commands */
    $commands = $this->fireflyContext()->get(CommandBus::class);
    /** @var QueryBus $queries */
    $queries = $this->fireflyContext()->get(QueryBus::class);

    /** @var string $walletId */
    $walletId = $commands->send(new OpenWallet('owner-C', Currency::EUR));
    $commands->send(new Deposit($walletId, 5000));

    // A principal carrying NEITHER ROLE_ADMIN NOR ROLE_WALLET_OWNER: the SecurityCommandAuthorizer runs the
    // #[PreAuthorize] at the bus BEFORE the handler, so the withdraw is denied and never touches the balance.
    SecurityContextHolder::setContext(new SecurityContext(
        Authentication::authenticated('mallory', 'mallory', [new SimpleGrantedAuthority('ROLE_USER')])
    ));

    $denied = null;
    try {
        $commands->send(new Withdraw($walletId, 1000));
    } catch (CommandProcessingException $e) {
        $denied = $e;
    }

    // Denied withdraw surfaces as CommandProcessingException wrapping the AuthorizationException, and — proving the
    // guard runs BEFORE the handler — the balance is untouched (5000, not 4000).
    expect($denied)->toBeInstanceOf(CommandProcessingException::class);
    expect($denied?->getPrevious())->toBeInstanceOf(AuthorizationException::class);
    expect($queries->ask(new GetBalance($walletId)))->toBe(5000);

    // Same withdraw, now with ROLE_WALLET_OWNER granted: the guard passes and the debit goes through.
    SecurityContextHolder::setContext(new SecurityContext(
        Authentication::authenticated('owner-C', 'owner-C', [new SimpleGrantedAuthority('ROLE_WALLET_OWNER')])
    ));

    $commands->send(new Withdraw($walletId, 1000));

    expect($queries->ask(new GetBalance($walletId)))->toBe(4000);
})->group('lumen');

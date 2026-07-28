<?php

declare(strict_types=1);

namespace Lumen\Tests\Application;

use Firefly\Cqrs\Command\CommandBus;
use Firefly\Cqrs\Exception\CommandProcessingException;
use Firefly\Cqrs\Query\QueryBus;
use Firefly\Kernel\Exception\Business\ConflictException;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Lumen\Application\Command\Deposit;
use Lumen\Application\Command\OpenWallet;
use Lumen\Application\Command\Withdraw;
use Lumen\Application\Query\GetBalance;
use Lumen\Domain\Currency;
use Lumen\Tests\LumenTestCase;

uses(LumenTestCase::class);

// S6 added #[PreAuthorize] to WithdrawHandler, so the overdraw test below must run under an authorized principal;
// clear it afterwards so no principal leaks into another test (mirrors SecurityAuthorizerTest's teardown).
afterEach(fn () => SecurityContextHolder::clearContext());

it('opens, deposits and reads the persisted balance through the real buses', function () {
    /** @var LumenTestCase $this */
    /** @var CommandBus $commands */
    $commands = $this->fireflyContext()->get(CommandBus::class);
    /** @var QueryBus $queries */
    $queries = $this->fireflyContext()->get(QueryBus::class);

    /** @var string $walletId */
    $walletId = $commands->send(new OpenWallet('owner-1', Currency::EUR));
    $commands->send(new Deposit($walletId, 5000));

    // Proves the #[Transactional] handler actually COMMITTED: GetBalance reloads the row from sqlite,
    // so a deposit that ran at transaction level 0 (never committed) would read 0 here, not 5000.
    expect($queries->ask(new GetBalance($walletId)))->toBe(5000);
})->group('lumen');

it('rejects an overdraw with a category-preserving CommandProcessingException and leaves the balance intact', function () {
    /** @var LumenTestCase $this */
    /** @var CommandBus $commands */
    $commands = $this->fireflyContext()->get(CommandBus::class);
    /** @var QueryBus $queries */
    $queries = $this->fireflyContext()->get(QueryBus::class);

    /** @var string $walletId */
    $walletId = $commands->send(new OpenWallet('owner-2', Currency::EUR));

    // WithdrawHandler is now #[PreAuthorize]-guarded; grant WALLET_OWNER so the command REACHES the handler and the
    // assertion under test — that the domain overdraw ConflictException surfaces through the bus — still holds.
    SecurityContextHolder::setContext(new SecurityContext(
        Authentication::authenticated('owner-2', 'owner-2', [new SimpleGrantedAuthority('ROLE_WALLET_OWNER')])
    ));

    $caught = null;
    try {
        $commands->send(new Withdraw($walletId, 1));
    } catch (CommandProcessingException $e) {
        $caught = $e;
    }

    // The domain ConflictException surfaces THROUGH the bus wrapped in CommandProcessingException,
    // preserving its category as the `previous` cause, and the failed unit of work rolled back (balance still 0).
    expect($caught)->toBeInstanceOf(CommandProcessingException::class);
    expect($caught?->getPrevious())->toBeInstanceOf(ConflictException::class);
    expect($queries->ask(new GetBalance($walletId)))->toBe(0);
})->group('lumen');

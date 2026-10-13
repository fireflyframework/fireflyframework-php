<?php

declare(strict_types=1);

namespace Lumen\Tests\Web;

use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Lumen\Infrastructure\Payout\SimulatedBankGateway;
use Lumen\Tests\Application\LumenOrchestrationTestCase;

uses(LumenOrchestrationTestCase::class);

afterEach(fn () => SecurityContextHolder::clearContext());

beforeEach(function () {
    // The saga's debit is a Withdraw, guarded by #[PreAuthorize] at the bus; set before the request, the context
    // survives into the in-process dispatch exactly as WalletRestTest's authorized withdraw relies on.
    SecurityContextHolder::setContext(new SecurityContext(
        Authentication::authenticated('owner-rest', 'owner-rest', [new SimpleGrantedAuthority('ROLE_WALLET_OWNER')])
    ));
});

it('pays out over REST and answers 201 with the bank reference', function () {
    /** @var LumenOrchestrationTestCase $this */
    /** @var string $id */
    $id = $this->postJson('/api/v1/wallets', ['owner_id' => 'owner-rest', 'currency' => 'EUR'])->json('wallet_id');
    $this->postJson("/api/v1/wallets/{$id}/deposit", ['amount_minor' => 50000]);

    $this->postJson("/api/v1/wallets/{$id}/payouts", ['iban' => 'ES9121000418450200051332', 'amount_minor' => 20000])
        ->assertStatus(201)
        ->assertJson(['wallet_id' => $id, 'status' => 'COMPLETED', 'bank_reference' => 'bank-1']);

    $this->getJson("/api/v1/wallets/{$id}/balance")->assertJson(['balance_minor' => 30000]);
})->group('lumen');

it('answers 409 PAYOUT_FAILED, with the money back in the wallet, when the bank refuses', function () {
    /** @var LumenOrchestrationTestCase $this */
    /** @var string $id */
    $id = $this->postJson('/api/v1/wallets', ['owner_id' => 'owner-rest', 'currency' => 'EUR'])->json('wallet_id');
    $this->postJson("/api/v1/wallets/{$id}/deposit", ['amount_minor' => 50000]);

    $this->postJson("/api/v1/wallets/{$id}/payouts", ['iban' => 'XX0012345678', 'amount_minor' => 20000])
        ->assertStatus(409)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'PAYOUT_FAILED');

    $this->getJson("/api/v1/wallets/{$id}/balance")->assertJson(['balance_minor' => 50000]);
})->group('lumen');

it('answers an overdrawing payout with the withdraw\'s own 409, before the bank is called', function () {
    /** @var LumenOrchestrationTestCase $this */
    /** @var string $id */
    $id = $this->postJson('/api/v1/wallets', ['owner_id' => 'owner-rest', 'currency' => 'EUR'])->json('wallet_id');
    $this->postJson("/api/v1/wallets/{$id}/deposit", ['amount_minor' => 1000]);

    $this->postJson("/api/v1/wallets/{$id}/payouts", ['iban' => 'ES9121000418450200051332', 'amount_minor' => 5000])
        ->assertStatus(409)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('category', 'business');

    expect($this->app()->make(SimulatedBankGateway::class)->calls)->toBe([]);
})->group('lumen');

it('validates the payout body before the saga starts', function () {
    /** @var LumenOrchestrationTestCase $this */
    /** @var string $id */
    $id = $this->postJson('/api/v1/wallets', ['owner_id' => 'owner-rest', 'currency' => 'EUR'])->json('wallet_id');

    $this->postJson("/api/v1/wallets/{$id}/payouts", ['iban' => '', 'amount_minor' => -1])
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json');
})->group('lumen');

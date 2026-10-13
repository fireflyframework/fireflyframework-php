<?php

declare(strict_types=1);

namespace Lumen\Tests\Web;

use Firefly\Cqrs\Command\CommandBus;
use Firefly\Orchestration\Persistence\OrchestrationSchema;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Illuminate\Support\Facades\DB;
use Lumen\Application\Command\Deposit;
use Lumen\Application\Payout\PayoutSaga;
use Lumen\Infrastructure\Payout\SimulatedBankGateway;
use Lumen\Tests\Application\LumenOrchestrationTestCase;
use RuntimeException;

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

it('answers 500 PAYOUT_COMPENSATION_FAILED, never the bank\'s 409, when the refund after a refusal fails', function () {
    /** @var LumenOrchestrationTestCase $this */
    /** @var string $id */
    $id = $this->postJson('/api/v1/wallets', ['owner_id' => 'owner-rest', 'currency' => 'EUR'])->json('wallet_id');
    $this->postJson("/api/v1/wallets/{$id}/deposit", ['amount_minor' => 50000]);

    // The bank refuses the account at submit, and the refund (a Deposit through the bus) then fails too: the debit is
    // done, the money is neither at the bank nor back in the wallet. PayoutSaga is a singleton built at boot, so the
    // failing bus goes in through a fresh saga bean; the debit still runs through the real bus.
    /** @var CommandBus $real */
    $real = $this->fireflyContext()->get(CommandBus::class);
    $commands = new class($real) implements CommandBus
    {
        public function __construct(private readonly CommandBus $inner) {}

        public function send(object $command): mixed
        {
            if ($command instanceof Deposit) {
                throw new RuntimeException('The ledger is unavailable.');
            }

            return $this->inner->send($command);
        }
    };
    $this->app()->instance(PayoutSaga::class, new PayoutSaga($commands, $this->app()->make(SimulatedBankGateway::class)));

    $response = $this->postJson("/api/v1/wallets/{$id}/payouts", ['iban' => 'XX0012345678', 'amount_minor' => 20000])
        ->assertStatus(500)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'PAYOUT_COMPENSATION_FAILED')
        ->assertJsonPath('uncompensated_steps', ['debit']);

    // Under the default strict-sequential policy the error handler continues past the failed refund, so the execution
    // ends FAILED with debit marked COMPENSATION_FAILED; the answer is read from the step, not the execution status.
    $payoutId = $response->json('payout_id');
    expect($payoutId)->toBeString()
        ->and(DB::table(OrchestrationSchema::EXECUTIONS)->where('correlation_id', $payoutId)->value('status'))->toBe('FAILED')
        ->and(DB::table(OrchestrationSchema::DEAD_LETTERS)->where('correlation_id', $payoutId)->exists())->toBeTrue();

    $this->getJson("/api/v1/wallets/{$id}/balance")->assertJson(['balance_minor' => 30000]);
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

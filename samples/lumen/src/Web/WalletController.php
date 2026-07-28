<?php

declare(strict_types=1);

namespace Lumen\Web;

use Firefly\Cqrs\Command\CommandBus;
use Firefly\Cqrs\Query\QueryBus;
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;
use Firefly\Validation\Valid;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\PathVariable;
use Firefly\Web\Attributes\PostMapping;
use Firefly\Web\Attributes\RequestBody;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;
use Lumen\Application\Command\Deposit;
use Lumen\Application\Command\OpenWallet;
use Lumen\Application\Command\Transfer;
use Lumen\Application\Command\Withdraw;
use Lumen\Application\Query\GetBalance;
use Lumen\Application\Query\GetLedger;
use Lumen\Domain\Currency;
use Lumen\Domain\LedgerEntry;
use Lumen\Web\Dto\AmountRequest;
use Lumen\Web\Dto\OpenWalletRequest;
use Lumen\Web\Dto\TransferRequest;

/**
 * The wallet REST surface: thin HTTP-onto-CQRS mapping, no business logic. Every method builds a
 * command/query, dispatches it through the bus, and shapes the return array — the same rule the sample's
 * CQRS handlers already enforce for the domain layer. A domain/business fault raised by the bus (e.g. an
 * unknown wallet's ResourceNotFoundException, an overdraw's ConflictException, or a denied #[PreAuthorize]
 * on withdraw) surfaces as CommandProcessingException/QueryProcessingException and renders RFC-7807
 * problem-details via the framework's global ProblemDetailsRenderer — no local #[ExceptionHandler] needed.
 */
#[RestController]
#[RequestMapping('/api/v1/wallets')]
final class WalletController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
    ) {}

    /** @return array{wallet_id: string} */
    #[PostMapping(status: 201)]
    public function open(#[Valid] #[RequestBody] OpenWalletRequest $body): array
    {
        /** @var string $id */
        $id = $this->commands->send(new OpenWallet($body->owner_id, Currency::from($body->currency)));

        return ['wallet_id' => $id];
    }

    /** @return array{wallet_id: string, balance_minor: int} */
    #[PostMapping('/{id}/deposit')]
    public function deposit(#[PathVariable] string $id, #[Valid] #[RequestBody] AmountRequest $body): array
    {
        /** @var int $balance */
        $balance = $this->commands->send(new Deposit($id, $body->amount_minor));

        return ['wallet_id' => $id, 'balance_minor' => $balance];
    }

    /**
     * Debits `amount_minor` from the wallet. Guarded upstream at the bus: WithdrawHandler carries
     * #[PreAuthorize("hasRole('ADMIN') or hasRole('WALLET_OWNER')")] (S6), enforced by
     * SecurityCommandAuthorizer BEFORE the handler runs. Without an authorized principal in the
     * SecurityContextHolder, the bus denies the command and the AuthorizationException it wraps renders
     * as a 403 problem-details response — this endpoint is secured, not broken.
     *
     * @return array{wallet_id: string, balance_minor: int}
     */
    #[PostMapping('/{id}/withdraw')]
    public function withdraw(#[PathVariable] string $id, #[Valid] #[RequestBody] AmountRequest $body): array
    {
        /** @var int $balance */
        $balance = $this->commands->send(new Withdraw($id, $body->amount_minor));

        return ['wallet_id' => $id, 'balance_minor' => $balance];
    }

    /**
     * Moves `amount_minor` from the source wallet to the destination wallet as one atomic unit of work
     * (TransferHandler's #[Transactional] boundary), then reads back both fresh balances for the response.
     *
     * @return array{source_wallet_id: string, destination_wallet_id: string, source_balance_minor: int, destination_balance_minor: int}
     */
    #[PostMapping('/transfers')]
    public function transfer(#[Valid] #[RequestBody] TransferRequest $body): array
    {
        $this->commands->send(new Transfer($body->source_wallet_id, $body->destination_wallet_id, $body->amount_minor));

        /** @var int $sourceBalance */
        $sourceBalance = $this->queries->ask(new GetBalance($body->source_wallet_id));
        /** @var int $destinationBalance */
        $destinationBalance = $this->queries->ask(new GetBalance($body->destination_wallet_id));

        return [
            'source_wallet_id' => $body->source_wallet_id,
            'destination_wallet_id' => $body->destination_wallet_id,
            'source_balance_minor' => $sourceBalance,
            'destination_balance_minor' => $destinationBalance,
        ];
    }

    /** @return array{wallet_id: string, balance_minor: int} */
    #[GetMapping('/{id}/balance')]
    public function balance(#[PathVariable] string $id): array
    {
        /** @var int|null $balance */
        $balance = $this->queries->ask(new GetBalance($id));
        if ($balance === null) {
            throw new ResourceNotFoundException("Wallet {$id} not found");
        }

        return ['wallet_id' => $id, 'balance_minor' => $balance];
    }

    /** @return array{wallet_id: string, entries: list<LedgerEntry>} */
    #[GetMapping('/{id}/ledger')]
    public function ledger(#[PathVariable] string $id): array
    {
        /** @var list<LedgerEntry> $entries */
        $entries = $this->queries->ask(new GetLedger($id));

        return ['wallet_id' => $id, 'entries' => $entries];
    }
}

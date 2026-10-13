<?php

declare(strict_types=1);

namespace Lumen\Web;

use Firefly\Container\Attributes\Lazy;
use Firefly\Kernel\Exception\Business\ConflictException;
use Firefly\Orchestration\Saga\Engine\SagaEngine;
use Firefly\Validation\Valid;
use Firefly\Web\Attributes\PathVariable;
use Firefly\Web\Attributes\PostMapping;
use Firefly\Web\Attributes\RequestBody;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;
use Lumen\Web\Dto\PayoutRequest;

/**
 * The payout endpoint: runs the `payout` saga and shapes its result. The saga runs inside the request (PHP is
 * synchronous); its checkpoints and, on failure, its dead letter are in the orchestration store either way.
 *
 * #[Lazy] because the SagaEngine it needs is firefly/orchestration's, bound only where that package's providers are
 * registered: the controller is built on its first request rather than at boot, so an application (or a test
 * harness) that leaves orchestration out still boots, and only this route needs it.
 */
#[Lazy]
#[RestController]
#[RequestMapping('/api/v1/wallets')]
final class PayoutController
{
    public function __construct(private readonly SagaEngine $sagas) {}

    /** @return array{payout_id: string, wallet_id: string, status: string, bank_reference: string} */
    #[PostMapping('/{id}/payouts', status: 201)]
    public function payout(#[PathVariable] string $id, #[Valid] #[RequestBody] PayoutRequest $body): array
    {
        $result = $this->sagas->execute('payout', ['walletId' => $id, 'iban' => $body->iban, 'amountMinor' => $body->amount_minor]);

        if (! $result->success) {
            // The debit never happened, so nothing was undone: the bus's own refusal — 401, 403, 404, or the 409 of
            // an overdraw — is the honest answer, exactly as POST /withdraw would have given it.
            if ($result->firstErrorStepId() === 'debit' && $result->error !== null) {
                throw $result->error;
            }

            throw new ConflictException(
                sprintf('Payout %s failed at step "%s" and ended %s: %s', $result->correlationId, (string) $result->firstErrorStepId(), $result->status->value, $result->error?->getMessage() ?? 'no error was recorded'),
                'PAYOUT_FAILED',
                $result->error,
            );
        }

        $reference = ($result->steps['confirm'] ?? null)?->result;

        return [
            'payout_id' => $result->correlationId,
            'wallet_id' => $id,
            'status' => $result->status->value,
            'bank_reference' => is_string($reference) ? $reference : '',
        ];
    }
}

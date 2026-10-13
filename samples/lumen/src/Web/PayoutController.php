<?php

declare(strict_types=1);

namespace Lumen\Web;

use Firefly\Container\Attributes\Lazy;
use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Exception\Business\ConflictException;
use Firefly\Kernel\Exception\FireflyException;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\StepStatus;
use Firefly\Orchestration\Saga\Engine\SagaEngine;
use Firefly\Orchestration\Saga\Engine\SagaResult;
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
            // A compensation that did not finish: money may have left the wallet and not come back. That is never a
            // business refusal; it is the one outcome an operator must act on, so it gets its own server-side answer
            // naming the steps left undone and the payout id the execution is checkpointed and dead-lettered under.
            // Asked of the steps as well as the status: under the default strict-sequential policy a compensation
            // that throws marks its step COMPENSATION_FAILED and the error handler continues, so the saga still
            // ends FAILED, while a policy that stops the sweep ends it COMPENSATION_FAILED.
            $undone = $this->undoneSteps($result);
            if ($undone !== [] || $result->status === ExecutionStatus::CompensationFailed) {
                throw (new FireflyException(
                    sprintf('Payout %s failed at step "%s" and was not fully undone; steps left uncompensated: %s.', $result->correlationId, (string) $this->failingStep($result), implode(', ', $undone)),
                    'PAYOUT_COMPENSATION_FAILED',
                    500,
                    ErrorCategory::Internal,
                    ErrorSeverity::Critical,
                    $result->error,
                ))->withExtensions(['payout_id' => $result->correlationId, 'uncompensated_steps' => $undone]);
            }

            // The debit itself was refused, so nothing was undone: the bus's own refusal — 401, 403, 404, or the 409
            // of an overdraw — is the honest answer, exactly as POST /withdraw would have given it. Asked of the debit
            // step's own status, not firstErrorStepId(), which also counts a step whose compensation failed.
            $debit = $result->steps['debit'] ?? null;
            if ($debit !== null && $debit->status === StepStatus::Failed && $result->error !== null) {
                throw $result->error;
            }

            throw new ConflictException(
                sprintf('Payout %s failed at step "%s" and ended %s: %s', $result->correlationId, (string) $this->failingStep($result), $result->status->value, $result->error?->getMessage() ?? 'no error was recorded'),
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

    /**
     * The steps a failed payout left changed: the engine's own list for a COMPENSATION_FAILED saga, plus any step
     * whose compensation threw, in saga order.
     *
     * @return list<string>
     */
    private function undoneSteps(SagaResult $result): array
    {
        $undone = [];
        foreach ($result->steps as $stepId => $step) {
            if ($step->status === StepStatus::CompensationFailed || in_array($stepId, $result->uncompensatedSteps, true)) {
                $undone[] = (string) $stepId;
            }
        }

        return $undone;
    }

    /** The step whose own action failed (FAILED or TIMED_OUT), as opposed to one whose compensation did. */
    private function failingStep(SagaResult $result): ?string
    {
        foreach ($result->steps as $stepId => $step) {
            if ($step->status === StepStatus::Failed || $step->status === StepStatus::TimedOut) {
                return $stepId;
            }
        }

        return $result->firstErrorStepId();
    }
}

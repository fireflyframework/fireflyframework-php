<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Telemetry;

use Firefly\FeatureFlags\FeatureFlags;
use OpenFeature\interfaces\flags\EvaluationContext;
use OpenFeature\interfaces\hooks\Hook;
use OpenFeature\interfaces\hooks\HookContext;
use OpenFeature\interfaces\hooks\HookHints;
use OpenFeature\interfaces\provider\ResolutionDetails;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use WeakMap;

/**
 * Counts every evaluation of the `firefly` client in feature_flag_evaluations_total{flag, variant, reason}: the
 * variant is `none` when there is none, and a failed evaluation counts as reason `ERROR`, variant `none`.
 *
 * Each evaluation is counted ONCE, in `finally`, with the outcome the caller got. open-feature/sdk 2.3 hands
 * `finally` no details, so `after` and `error` only note the outcome, keyed by the evaluation's HookContext (the
 * SDK passes the same instance to every phase of one evaluation). A hook that throws once this one's `after` has
 * run turns the evaluation into ERROR + the caller's default and runs every `error` hook: this one's then replaces
 * the noted success, and nothing is counted twice. (PyFly counts in `finally_after`, which its SDK hands the final
 * details.)
 *
 * A preview (hook hint FeatureFlags::PREVIEW_HINT, CONTRACT.md I-3) is not counted.
 *
 * The SDK runs `after` hooks WITHOUT a guard and turns any exception into ERROR + the caller's default, so this
 * hook never throws: a recorder that fails is logged at DEBUG. Telemetry never changes a flag's value.
 */
final class MetricsHook implements Hook
{
    /** @var WeakMap<HookContext, array{string, string}> the [variant, reason] noted for each evaluation in flight */
    private WeakMap $outcomes;

    public function __construct(
        private readonly FeatureFlagMetrics $metrics,
        private readonly LoggerInterface $logger = new NullLogger,
    ) {
        $this->outcomes = new WeakMap;
    }

    public function before(HookContext $context, HookHints $hints): ?EvaluationContext
    {
        return null;
    }

    public function after(HookContext $context, ResolutionDetails $details, HookHints $hints): void
    {
        if ($hints->get(FeatureFlags::PREVIEW_HINT) === true) {
            return;
        }

        $variant = $details->getVariant();
        $this->outcomes[$context] = $details->getError() !== null || $details->getReason() === 'ERROR'
            ? ['none', 'ERROR']
            : [$variant === null || $variant === '' ? 'none' : $variant, $details->getReason() ?? 'UNKNOWN'];
    }

    public function error(HookContext $context, Throwable $error, HookHints $hints): void
    {
        if ($hints->get(FeatureFlags::PREVIEW_HINT) === true) {
            return;
        }

        $this->outcomes[$context] = ['none', 'ERROR'];
    }

    public function finally(HookContext $context, HookHints $hints): void
    {
        $outcome = $this->outcomes[$context] ?? null;
        unset($this->outcomes[$context]);
        if ($outcome === null) {
            return;
        }

        [$variant, $reason] = $outcome;
        try {
            $this->metrics->recordEvaluation($context->getFlagKey(), $variant, $reason);
        } catch (Throwable $failure) {
            $this->logger->debug('Recording the evaluation of feature flag [{flag}] failed: {error}', ['flag' => $context->getFlagKey(), 'error' => $failure->getMessage()]);
        }
    }

    public function supportsFlagValueType(string $flagValueType): bool
    {
        return true;
    }
}

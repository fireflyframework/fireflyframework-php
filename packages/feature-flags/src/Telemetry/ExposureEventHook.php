<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Telemetry;

use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\FeatureFlags\Event\FeatureFlagEvaluated;
use Firefly\FeatureFlags\FeatureFlags;
use Firefly\FeatureFlags\Provider\ValueCopy;
use OpenFeature\interfaces\flags\EvaluationContext;
use OpenFeature\interfaces\hooks\Hook;
use OpenFeature\interfaces\hooks\HookContext;
use OpenFeature\interfaces\hooks\HookHints;
use OpenFeature\interfaces\provider\ErrorCode;
use OpenFeature\interfaces\provider\ResolutionDetails;
use OpenFeature\interfaces\provider\ThrowableWithResolutionError;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use WeakMap;

/**
 * Publishes one FeatureFlagEvaluated per evaluation of the `firefly` client: the exposure record an experiment
 * needs. Attached only when firefly.feature-flags.events.evaluations is on.
 *
 * The event says what the caller got: published once, in `finally`, from what `after` or `error` noted for that
 * evaluation (see MetricsHook for why — a hook failing after this one's `after` makes the evaluation ERROR + the
 * default, and `error` then replaces the noted success). A failure carries the OpenFeature error code: the
 * resolution's, or the thrown one's when it carries a code (ThrowableWithResolutionError), GENERAL otherwise —
 * the code the SDK answers the caller with. Its variant is null.
 *
 * The value is a deep copy (ValueCopy) of the value served: PHP shares objects, and a stdClass inside an object
 * flag's array (an application provider's value, or the caller's own default) would otherwise be the caller's to
 * change after the record was made.
 *
 * A preview (hook hint FeatureFlags::PREVIEW_HINT, CONTRACT.md I-3) publishes nothing. A listener that throws is
 * logged at DEBUG and never changes the value: the SDK runs `after` hooks unguarded, so this hook never throws.
 */
final class ExposureEventHook implements Hook
{
    /** @var WeakMap<HookContext, FeatureFlagEvaluated> the exposure noted for each evaluation in flight */
    private WeakMap $exposures;

    public function __construct(
        private readonly ApplicationEventPublisher $events,
        private readonly LoggerInterface $logger = new NullLogger,
    ) {
        $this->exposures = new WeakMap;
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

        $error = $details->getError();
        $failed = $error !== null || $details->getReason() === 'ERROR';

        $this->exposures[$context] = new FeatureFlagEvaluated(
            $context->getFlagKey(),
            ValueCopy::of($details->getValue()),
            $failed ? null : $details->getVariant(),
            $failed ? 'ERROR' : ($details->getReason() ?? 'UNKNOWN'),
            $error?->getResolutionErrorCode()->getValue(),
            $context->getEvaluationContext()->getTargetingKey(),
        );
    }

    public function error(HookContext $context, Throwable $error, HookHints $hints): void
    {
        if ($hints->get(FeatureFlags::PREVIEW_HINT) === true) {
            return;
        }

        $this->exposures[$context] = new FeatureFlagEvaluated(
            $context->getFlagKey(),
            ValueCopy::of($context->getDefaultValue()),
            null,
            'ERROR',
            self::errorCode($error),
            $context->getEvaluationContext()->getTargetingKey(),
        );
    }

    public function finally(HookContext $context, HookHints $hints): void
    {
        $event = $this->exposures[$context] ?? null;
        unset($this->exposures[$context]);
        if ($event === null) {
            return;
        }

        try {
            $this->events->publish($event);
        } catch (Throwable $failure) {
            $this->logger->debug('Publishing the exposure of feature flag [{flag}] failed: {error}', ['flag' => $event->key, 'error' => $failure->getMessage()]);
        }
    }

    public function supportsFlagValueType(string $flagValueType): bool
    {
        return true;
    }

    /** The code the SDK answers a thrown failure with: the one it carries, else GENERAL. */
    private static function errorCode(Throwable $error): string
    {
        $general = ErrorCode::GENERAL()->getValue();
        if (! $error instanceof ThrowableWithResolutionError) {
            return $general;
        }

        try {
            return $error->getResolutionError()->getResolutionErrorCode()->getValue();
        } catch (Throwable) {
            return $general;
        }
    }
}

<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Context;

use DateTime;
use DateTimeImmutable;
use OpenFeature\implementation\flags\Attributes;
use OpenFeature\implementation\flags\EvaluationContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Builds the evaluation context (CONTRACT.md "Evaluation context"): the ambient one from the contributors, then the
 * caller's explicit attributes over it — the caller wins, attribute by attribute; a `targetingKey` in the explicit
 * attributes (a non-empty string; never kept as an attribute) wins over the ambient key, and the $targetingKey
 * argument over both. A contributor that throws is skipped (logged at DEBUG): context is best-effort, an evaluation
 * must not fail.
 *
 * Not ambient (`ambient: false`, the management preview): only the PROCESS attributes — what the
 * ApplicationEvaluationContextContributor gives, `application` and `profiles` — under the explicit context. No
 * other contributor runs, so neither the caller's principal, roles nor tenant can reach the evaluation.
 *
 * What OpenFeature's Attributes cannot carry through the SDK client is dropped: an object other than a date-time,
 * and a numeric-looking name (an int key in PHP: the SDK's attribute merge, which every client evaluation runs,
 * drops every attribute when the first name is an int and fails the evaluation on a later one). Date-times pass
 * through unconverted — the evaluator turns them into epoch milliseconds — an immutable one as the equal DateTime
 * the SDK's attribute type requires (same instant, zone and microseconds).
 */
final class EvaluationContextResolver
{
    /**
     * @param  list<EvaluationContextContributor>  $contributors  in #[Order] order
     */
    public function __construct(
        private readonly array $contributors = [],
        private readonly LoggerInterface $logger = new NullLogger,
    ) {}

    /** Every contributor, in order. */
    public function ambient(): EvaluationContextBuilder
    {
        return $this->contribute($this->contributors);
    }

    /** The process attributes only: the ApplicationEvaluationContextContributor's `application` and `profiles`. */
    public function process(): EvaluationContextBuilder
    {
        return $this->contribute(array_values(array_filter(
            $this->contributors,
            static fn (EvaluationContextContributor $contributor): bool => $contributor instanceof ApplicationEvaluationContextContributor,
        )));
    }

    /**
     * @param  array<array-key, mixed>  $explicit  attribute name => value; `targetingKey` names the targeting key
     */
    public function resolve(array $explicit = [], ?string $targetingKey = null, bool $ambient = true): EvaluationContext
    {
        $base = $ambient ? $this->ambient() : $this->process();
        $explicitKey = $explicit['targetingKey'] ?? null;
        unset($explicit['targetingKey']);

        $key = match (true) {
            $targetingKey !== null && $targetingKey !== '' => $targetingKey,
            is_string($explicitKey) && $explicitKey !== '' => $explicitKey,
            default => $base->targetingKey(),
        };

        $attributes = [];
        foreach (array_replace($base->attributes(), $explicit) as $name => $value) {
            if (! is_string($name)) {
                continue;
            }

            if ($value instanceof DateTimeImmutable) {
                $attributes[$name] = DateTime::createFromImmutable($value);
            } elseif ($value === null || is_scalar($value) || is_array($value) || $value instanceof DateTime) {
                $attributes[$name] = $value;
            }
        }

        return new EvaluationContext($key, new Attributes($attributes));
    }

    /**
     * @param  list<EvaluationContextContributor>  $contributors
     */
    private function contribute(array $contributors): EvaluationContextBuilder
    {
        $context = new EvaluationContextBuilder;
        foreach ($contributors as $contributor) {
            try {
                $contributor->contribute($context);
            } catch (Throwable $failure) {
                $this->logger->debug('Evaluation-context contributor [{contributor}] failed and was skipped: {error}', [
                    'contributor' => $contributor::class,
                    'error' => $failure->getMessage(),
                ]);
            }
        }

        return $context;
    }
}

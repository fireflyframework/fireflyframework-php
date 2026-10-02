<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Context;

use DateTime;
use DateTimeImmutable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use OpenFeature\implementation\flags\Attributes;
use OpenFeature\implementation\flags\EvaluationContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use stdClass;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * Builds the evaluation context (CONTRACT.md "Evaluation context"): the ambient one from the contributors, then the
 * caller's explicit attributes over it — the caller wins, attribute by attribute; a `targetingKey` in the explicit
 * attributes wins over the ambient key, and the $targetingKey argument over both. The explicit `targetingKey` is
 * never kept as an attribute; it is a key when it is a non-empty string, an int (`['targetingKey' => $user->id]`,
 * read as `"42"`) or a Stringable such as a UUID. Any other type is refused with a DEBUG line naming it, and the
 * ambient key applies — and so is an Eloquent model or a collection (Arrayable/Jsonable): both are Stringable, but
 * as their JSON text, which would key a rollout on every attribute the model holds (pass `$user->id`). A
 * contributor that throws is skipped (logged at DEBUG): context is best-effort, an evaluation must not fail.
 *
 * Not ambient (`ambient: false`, the management preview): only the PROCESS attributes — what the
 * ApplicationEvaluationContextContributor gives, `application` and `profiles` — under the explicit context. No
 * other contributor runs, so neither the caller's principal, roles nor tenant can reach the evaluation.
 *
 * Top-level attributes are fitted to what OpenFeature's Attributes carry through the SDK client — null, scalars,
 * arrays and DateTime; any other value makes the SDK fail the whole evaluation, and a numeric-looking name (an int
 * key in PHP) makes its attribute merge, which every client evaluation runs, drop every attribute (first name) or
 * fail (a later one):
 *
 *   - a DateTimeImmutable becomes the equal DateTime (same instant, zone and microseconds); date-times are not
 *     converted further here — the evaluator turns them into epoch milliseconds;
 *   - a Stringable becomes its string — except an Eloquent model or a collection (Arrayable/Jsonable), whose string
 *     is its JSON text: it is dropped (pass the values a rule reads, such as `$user->plan`);
 *   - a JSON object (stdClass) becomes its members when they form Json's faithful array (non-empty, not a list).
 *     `{}` and a list-like object (`{"0": …}`) have no such form at the top level — as arrays they would read as
 *     lists, a different JSON value — so they are dropped rather than altered;
 *   - a numeric-looking name, an enum, any other object or a resource is dropped.
 *
 * Every dropped attribute is logged at DEBUG, once per evaluation, with its name and the reason. Values INSIDE an
 * array are left exactly as they are: the SDK carries arrays as `mixed[]` without looking in, and the evaluator
 * reads Json's faithful form (a nested stdClass is a JSON object there), so a nested `{}` keeps its meaning.
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
        $explicitKey = array_key_exists('targetingKey', $explicit) ? $this->explicitKey($explicit['targetingKey']) : null;
        unset($explicit['targetingKey']);

        $key = match (true) {
            $targetingKey !== null && $targetingKey !== '' => $targetingKey,
            $explicitKey !== null => $explicitKey,
            default => $base->targetingKey(),
        };

        $attributes = [];
        foreach (array_replace($base->attributes(), $explicit) as $name => $value) {
            if (! is_string($name)) {
                $this->dropped((string) $name, 'its name is numeric-looking (an int key in PHP), which OpenFeature cannot carry');

                continue;
            }

            $this->carry($attributes, $name, $value);
        }

        return new EvaluationContext($key, new Attributes($attributes));
    }

    /** The explicit targeting key as text: a non-empty string, an int or a Stringable; null (logged) otherwise. */
    private function explicitKey(mixed $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        if (is_string($key)) {
            return $key;
        }

        if (is_int($key)) {
            return (string) $key;
        }

        if ($key instanceof Arrayable || $key instanceof Jsonable) {
            return $this->refusedKey($key, 'an Eloquent model or a collection is not read as its JSON text: pass $user->id');
        }

        if ($key instanceof Stringable) {
            try {
                $text = (string) $key;
            } catch (Throwable $failure) {
                return $this->refusedKey($key, 'its string conversion failed: '.$failure->getMessage());
            }

            return $text === '' ? null : $text;
        }

        return $this->refusedKey($key, 'a targeting key is a string, an int or a Stringable');
    }

    private function refusedKey(mixed $key, string $reason): null
    {
        $this->logger->debug('The explicit evaluation-context targetingKey of type {type} was refused ({reason}); the ambient key, if any, applies.', [
            'type' => get_debug_type($key),
            'reason' => $reason,
        ]);

        return null;
    }

    /**
     * Puts the value OpenFeature carries for top-level attribute $name into $attributes, or logs why there is none.
     *
     * @param  array<string, bool|int|float|string|DateTime|array<array-key, mixed>|null>  $attributes
     */
    private function carry(array &$attributes, string $name, mixed $value): void
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value) || is_array($value) || $value instanceof DateTime) {
            $attributes[$name] = $value;

            return;
        }

        if ($value instanceof DateTimeImmutable) {
            $attributes[$name] = DateTime::createFromImmutable($value);

            return;
        }

        if ($value instanceof Arrayable || $value instanceof Jsonable) {
            $this->dropped($name, 'an Eloquent model or a collection ('.get_debug_type($value).'), which is not read as its JSON text: pass the values a rule reads, such as $user->plan');

            return;
        }

        if ($value instanceof Stringable) {
            try {
                $attributes[$name] = (string) $value;
            } catch (Throwable $failure) {
                $this->dropped($name, 'its string conversion failed: '.$failure->getMessage());
            }

            return;
        }

        if ($value instanceof stdClass) {
            $members = get_object_vars($value);
            if ($members !== [] && ! array_is_list($members)) {
                $attributes[$name] = $members;
            } else {
                $this->dropped($name, 'an empty or list-like JSON object ({} or {"0": …}) would read as a list once it is an array, the only top-level form OpenFeature carries');
            }

            return;
        }

        $this->dropped($name, match (true) {
            $value instanceof UnitEnum => 'an enum ('.$value::class.'), which OpenFeature cannot carry: pass its value',
            is_object($value) => 'an object of class '.$value::class.', which OpenFeature cannot carry: pass a JSON value, a date-time or a Stringable',
            default => 'a value of type '.get_debug_type($value).', which OpenFeature cannot carry',
        });
    }

    private function dropped(string $name, string $reason): void
    {
        $this->logger->debug('Evaluation-context attribute [{name}] was dropped: {reason}.', ['name' => $name, 'reason' => $reason]);
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

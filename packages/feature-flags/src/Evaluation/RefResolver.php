<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Evaluation;

use Firefly\FeatureFlags\Definition\Json;
use stdClass;

/**
 * `$ref` resolution inside one flag's targeting (CONTRACT.md, the `$ref` paragraphs; the reference algorithm is
 * expand_refs/bounded_size in the conformance generator, and PyFly's provider runs the same).
 *
 * A reference is an object with exactly one member, `$ref`, whose value is text. It resolves STRUCTURALLY on the
 * parsed document and TRANSITIVELY: it is replaced by the named evaluator's rule, itself expanded, whatever the
 * names sort as, and every string stays exactly as written (flagd's own textual substitution re-escapes a
 * backslash and resolves no reference an evaluator brings in). A reference to a name that is no evaluator, or to
 * one already being resolved on the same path (a cycle), stays in place: evaluating it is an unknown operation,
 * a PARSE_ERROR, as flagd reports a missing evaluator.
 *
 * Two limits keep a document from exhausting memory or time, per flag: the expansion may hold at most MAX_VALUES
 * JSON values (every object, array and scalar counts one, and so does every reference resolved on the way, so a
 * chain of references to references is bounded too) and nest at most MAX_DEPTH levels (the targeting object is
 * level 1, each object or array inside a container adds one, and a resolved reference takes the place of its
 * object without adding a level). withinLimits() decides both in one pass that never builds the expansion and
 * stops as soon as the budget is spent, so a fan-out of 2^60 references or a chain of 100 000 costs at most about
 * MAX_VALUES steps; expand() builds it, and is only ever called within the limits.
 *
 * expand() builds a new tree: arrays are values, and every object read as a stdClass (`{}`, `{"0": …}`) is a new
 * instance, so neither the document nor the evaluators it reads are ever modified, and two references to one
 * evaluator are two objects, as in the reference (JSON Logic's `==` compares objects by identity).
 */
final class RefResolver
{
    /** The most JSON values one flag's targeting may expand to, resolved references included. */
    public const int MAX_VALUES = 10_000;

    /** The most nesting levels one flag's expanded targeting may have. */
    public const int MAX_DEPTH = 128;

    /**
     * Whether $targeting, its references expanded, holds at most MAX_VALUES values and nests at most MAX_DEPTH
     * levels.
     *
     * @param  array<array-key, mixed>  $evaluators  the document's `$evaluators`
     */
    public static function withinLimits(mixed $targeting, array $evaluators): bool
    {
        $budget = self::MAX_VALUES;

        return self::fits($targeting, $evaluators, [], 1, $budget);
    }

    /**
     * $targeting with every resolvable reference replaced by its expanded evaluator; a missing name or a cycle
     * left as its `{"$ref": …}` object. Call it only within the limits (withinLimits()).
     *
     * @param  array<array-key, mixed>  $evaluators  the document's `$evaluators`
     */
    public static function expand(mixed $targeting, array $evaluators): mixed
    {
        return self::expanded($targeting, $evaluators, []);
    }

    /**
     * @param  array<array-key, mixed>  $evaluators
     * @param  array<array-key, true>  $resolving  the evaluator names being resolved on this path
     */
    private static function fits(mixed $node, array $evaluators, array $resolving, int $level, int &$budget): bool
    {
        while (($name = self::resolvable($node, $evaluators, $resolving)) !== null) {
            $resolving[$name] = true;
            $node = $evaluators[$name];
            if (--$budget < 0) {
                return false;
            }
        }

        if (--$budget < 0) {
            return false;
        }
        if (! is_array($node) && ! $node instanceof stdClass) {
            return true;
        }
        if ($level > self::MAX_DEPTH) {
            return false;
        }

        foreach (is_array($node) ? $node : get_object_vars($node) as $child) {
            if (! self::fits($child, $evaluators, $resolving, $level + 1, $budget)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<array-key, mixed>  $evaluators
     * @param  array<array-key, true>  $resolving
     */
    private static function expanded(mixed $node, array $evaluators, array $resolving): mixed
    {
        while (($name = self::resolvable($node, $evaluators, $resolving)) !== null) {
            $resolving[$name] = true;
            $node = $evaluators[$name];
        }

        if ($node instanceof stdClass) {
            $object = new stdClass;
            foreach (get_object_vars($node) as $member => $child) {
                $object->{$member} = self::expanded($child, $evaluators, $resolving);
            }

            return $object;
        }

        if (is_array($node)) {
            $array = [];
            foreach ($node as $key => $child) {
                $array[$key] = self::expanded($child, $evaluators, $resolving);
            }

            return $array;
        }

        return $node;
    }

    /**
     * The evaluator name $node references, when that evaluator exists and is not already being resolved on this
     * path; null otherwise (not a reference, a missing name, or a cycle).
     *
     * @param  array<array-key, mixed>  $evaluators
     * @param  array<array-key, true>  $resolving
     */
    private static function resolvable(mixed $node, array $evaluators, array $resolving): ?string
    {
        if (! Json::isObject($node)) {
            return null;
        }

        $members = Json::members($node);
        $name = count($members) === 1 ? ($members['$ref'] ?? null) : null;

        return is_string($name) && array_key_exists($name, $evaluators) && ! array_key_exists($name, $resolving) ? $name : null;
    }
}

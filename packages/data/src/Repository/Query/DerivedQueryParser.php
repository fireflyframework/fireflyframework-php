<?php

declare(strict_types=1);

namespace Firefly\Data\Repository\Query;

use InvalidArgumentException;

/**
 * Pure string parsing of a Spring-style derived-query method name into a ParsedQuery — NO reflection, the method
 * name is the only input. Grammar (extended Spring): prefixes find/count/exists/delete (with findFirst /
 * findTop{N} / findDistinct); And/Or connectors; the operators are matched LONGEST-FIRST (so GreaterThanEqual
 * beats GreaterThan, NotIn beats In, NotLike beats Like); each operator may carry an IgnoreCase suffix; a trailing
 * OrderBy{Field}{Asc|Desc} clause (chainable). Field segments are CamelCase and mapped to snake_case columns.
 */
final class DerivedQueryParser
{
    /**
     * Operator tokens in LONGEST-MATCH-FIRST order. Matching walks this list and takes the FIRST token the field
     * group ENDS with; the remainder is the field. `Equals` is the implicit default (no token). Reordering this
     * list (e.g. shortest-first) is the fault-injection tripwire: `StatusNotIn` would parse as field "StatusNot"
     * op "In", etc.
     *
     * @var list<string>
     */
    private const OPERATORS = [
        'GreaterThanEqual',
        'LessThanEqual',
        'GreaterThan',
        'LessThan',
        'Between',
        'NotLike',
        'Like',
        'NotIn',
        'In',
        'Containing',
        'StartingWith',
        'EndingWith',
        'IsNotNull',
        'IsNull',
        'Not',
        'True',
        'False',
    ];

    public static function parse(string $method): ParsedQuery
    {
        [$prefix, $top, $distinct, $remainder] = self::parsePrefix($method);
        [$predicatePart, $orderPart] = self::splitOrderBy($remainder);
        [$predicates, $connectors] = self::parsePredicates($predicatePart);

        return new ParsedQuery($prefix, $top, $distinct, $predicates, $connectors, self::parseOrders($orderPart));
    }

    /**
     * @return array{0: 'find'|'count'|'exists'|'delete', 1: int|null, 2: bool, 3: string}
     */
    private static function parsePrefix(string $method): array
    {
        if (str_starts_with($method, 'find')) {
            $rest = substr($method, 4);
            $top = null;
            $distinct = false;

            if (str_starts_with($rest, 'First')) {
                $top = 1;
                $rest = substr($rest, 5);
            } elseif (preg_match('/^Top(\d+)/', $rest, $m) === 1) {
                $top = (int) $m[1];
                $rest = substr($rest, strlen($m[0]));
            } elseif (str_starts_with($rest, 'Distinct')) {
                $distinct = true;
                $rest = substr($rest, 8);
            }

            if (! str_starts_with($rest, 'By')) {
                throw new InvalidArgumentException("Unparseable derived query [{$method}]: expected 'By' after the find prefix.");
            }

            return ['find', $top, $distinct, substr($rest, 2)];
        }

        foreach (['count', 'exists', 'delete'] as $prefix) {
            $marker = $prefix.'By';
            if (str_starts_with($method, $marker)) {
                return [$prefix, null, false, substr($method, strlen($marker))];
            }
        }

        throw new InvalidArgumentException("Unparseable derived query [{$method}]: unknown prefix.");
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitOrderBy(string $remainder): array
    {
        $parts = explode('OrderBy', $remainder, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    /**
     * @return array{0: list<Predicate>, 1: list<'And'|'Or'>}
     */
    private static function parsePredicates(string $part): array
    {
        if ($part === '') {
            return [[], []];
        }

        // Split at And/Or ONLY when the connector precedes a new CamelCase field (uppercase). This leaves fields
        // that merely start with "Or"/"And" (e.g. "OrderId") intact.
        $tokens = preg_split('/(And|Or)(?=[A-Z])/', $part, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($tokens === false) {
            $tokens = [$part];
        }

        $predicates = [];
        $connectors = [];
        foreach ($tokens as $index => $token) {
            if ($index % 2 === 0) {
                $predicates[] = self::parsePredicate($token);
            } else {
                /** @var 'And'|'Or' $token */
                $connectors[] = $token;
            }
        }

        return [$predicates, $connectors];
    }

    private static function parsePredicate(string $group): Predicate
    {
        $ignoreCase = false;
        if (str_ends_with($group, 'IgnoreCase')) {
            $ignoreCase = true;
            $group = substr($group, 0, -strlen('IgnoreCase'));
        }

        foreach (self::OPERATORS as $op) {
            if ($group !== $op && str_ends_with($group, $op)) {
                $field = substr($group, 0, -strlen($op));
                $boolLiteral = match ($op) {
                    'True' => true,
                    'False' => false,
                    default => null,
                };

                return new Predicate(self::toSnake($field), $op, $ignoreCase, $boolLiteral);
            }
        }

        return new Predicate(self::toSnake($group), 'Equals', $ignoreCase, null);
    }

    /**
     * @return list<OrderClause>
     */
    private static function parseOrders(string $orderPart): array
    {
        if ($orderPart === '') {
            return [];
        }

        $orders = [];
        $rest = $orderPart;

        while ($rest !== '') {
            if (preg_match('/^([A-Z][A-Za-z0-9]*?)(Asc|Desc)(?=[A-Z]|$)/', $rest, $m) === 1) {
                $orders[] = new OrderClause(self::toSnake($m[1]), strtolower($m[2]) === 'desc' ? 'desc' : 'asc');
                $rest = substr($rest, strlen($m[0]));
            } else {
                $orders[] = new OrderClause(self::toSnake($rest), 'asc');
                $rest = '';
            }
        }

        return $orders;
    }

    private static function toSnake(string $field): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $field));
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Data\Repository\Projection;

use BackedEnum;
use DateMalformedStringException;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Firefly\Data\Transaction\TransactionalManifest;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Throwable;

/**
 * Turns a database row into a #[Projection] DTO — REFLECTION-FREE. The DTO's constructor was reflected once,
 * at scan time, into the manifest's projection row (name, snake_case column, declared type, nullability,
 * whether a default exists); here that row drives `new $dto(...$named)`, a named-argument spread, so an
 * optional parameter whose column is absent simply takes its default and a missing REQUIRED column is a
 * ConfigurationException that names both the column and the parameter.
 *
 * Coercion is deliberately narrow — the scalar the driver hands back into the scalar the parameter declares,
 * a backed enum via tryFrom(), a date-time from its string — because a projection is a read model and a read
 * model with surprising conversions is worse than one that fails. Narrow means LOSSLESS: '150.75' into an
 * int, 'bronze' into an enum without that case, 'not a date' into a DateTimeImmutable are each refused with
 * one sentence that names the DTO, the column, the value and the parameter (a ConfigurationException, never
 * a bare ValueError, TypeError or DateMalformedStringException out of PHP), because the alternative is a
 * report that silently reads 150 where the row said 150.75. Anything that is not a scalar, enum or date-time
 * is passed through for the constructor to accept or reject.
 *
 * @phpstan-import-type ProjectionRow from TransactionalManifest
 * @phpstan-import-type ProjectionParameterRow from TransactionalManifest
 */
final class ProjectionHydrator
{
    /**
     * @param  ProjectionRow  $projection
     */
    public function __construct(private readonly array $projection) {}

    /**
     * @return class-string
     */
    public function dto(): string
    {
        return $this->projection['dto'];
    }

    /**
     * @return list<string>
     */
    public function columns(): array
    {
        return $this->projection['columns'];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function hydrate(array $row): object
    {
        $arguments = [];
        foreach ($this->projection['parameters'] as $parameter) {
            $column = $parameter['column'];
            if (! array_key_exists($column, $row)) {
                if ($parameter['optional']) {
                    continue;
                }

                throw new ConfigurationException(sprintf(
                    'Projection [%s] needs column [%s] for parameter $%s, but the row has only [%s].',
                    $this->dto(),
                    $column,
                    $parameter['name'],
                    implode(', ', array_keys($row)),
                ));
            }

            $arguments[$parameter['name']] = $this->coerce($row[$column], $parameter);
        }

        $dto = $this->dto();

        return new $dto(...$arguments);
    }

    /**
     * @param  ProjectionParameterRow  $parameter
     */
    private function coerce(mixed $value, array $parameter): mixed
    {
        $type = $parameter['type'];

        if ($value === null) {
            if ($parameter['nullable'] || $type === null || $type === 'mixed') {
                return null;
            }

            throw new ConfigurationException(sprintf(
                'Projection [%s]: column [%s] is NULL but parameter $%s is a non-nullable %s.',
                $this->dto(),
                $parameter['column'],
                $parameter['name'],
                $type,
            ));
        }

        return match ($type) {
            null, 'mixed' => $value,
            'int' => $this->int($value, $parameter),
            'float' => is_float($value) ? $value : (is_numeric($value) ? (float) $value : $this->mismatch($value, $parameter)),
            'string' => is_string($value) ? $value : (is_scalar($value) ? (string) $value : $this->mismatch($value, $parameter)),
            'bool' => is_bool($value) ? $value : $this->bool($value, $parameter),
            default => $this->object($value, $type, $parameter),
        };
    }

    /**
     * Only a value that IS an integer becomes an int: a PHP int, an integral string ('7', '-7', '+7' — what
     * emulated prepares hand back for an INT column, and what SQLite hands back for one with text affinity), or
     * a float with no fractional part that fits the platform int (a SQLite ROUND() or AVG() result). '150.75',
     * 150.75 and '9223372036854775808' are NOT quietly truncated to 150 or saturated to PHP_INT_MAX the way (int)
     * would do it: MySQL and PostgreSQL return DECIMAL/NUMERIC columns as strings, so an `int $amount` over such
     * a column would otherwise lose the fraction on every row with no error at all — exactly the surprising
     * conversion the class docblock promises not to make. FILTER_VALIDATE_INT is the integral-string test
     * because it refuses fractions, exponents, hex and out-of-range digits while tolerating the sign and the
     * surrounding whitespace a driver may emit. The float path checks the range BEFORE casting: (int) on a
     * float outside the int range is undefined, and deprecated since PHP 8.5.
     *
     * @param  ProjectionParameterRow  $parameter
     */
    private function int(mixed $value, array $parameter): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            $int = filter_var($value, FILTER_VALIDATE_INT);

            return $int === false ? $this->mismatch($value, $parameter) : $int;
        }

        if (is_float($value) && is_finite($value) && $value === floor($value)
            && $value >= (float) PHP_INT_MIN && $value < (float) PHP_INT_MAX) {
            return (int) $value;
        }

        $this->mismatch($value, $parameter);
    }

    /**
     * Only the spellings a driver actually hands back for a boolean column become a bool: 1/0 (MySQL tinyint,
     * SQLite), '1'/'0' (emulated prepares), 'true'/'false', 'yes'/'no', 'on'/'off' and ''. Anything else —
     * 'abc', 'active', 2 — is NOT quietly flattened to false, because that is exactly the surprising conversion
     * the class docblock promises not to make: a string column mapped onto a bool parameter is a misconfiguration
     * the developer needs to see, so FILTER_NULL_ON_FAILURE turns it into the named mismatch instead.
     *
     * @param  ProjectionParameterRow  $parameter
     */
    private function bool(mixed $value, array $parameter): bool
    {
        if (! is_scalar($value)) {
            $this->mismatch($value, $parameter);
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $this->mismatch($value, $parameter);
    }

    /**
     * @param  ProjectionParameterRow  $parameter
     */
    private function object(mixed $value, string $type, array $parameter): mixed
    {
        if (is_subclass_of($type, BackedEnum::class)) {
            return $this->enum($value, $type, $parameter);
        }

        if ($type === DateTimeImmutable::class || $type === DateTimeInterface::class) {
            return match (true) {
                is_string($value) => $this->date($value, DateTimeImmutable::class, $parameter),
                $value instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($value),
                default => $this->mismatch($value, $parameter),
            };
        }

        if ($type === DateTime::class) {
            return match (true) {
                is_string($value) => $this->date($value, DateTime::class, $parameter),
                $value instanceof DateTimeInterface => DateTime::createFromInterface($value),
                default => $this->mismatch($value, $parameter),
            };
        }

        return $value;
    }

    /**
     * A backed enum is looked up with tryFrom(), never from(): from() answers a value the enum does not define
     * with a bare ValueError that names neither the column nor the DTO. Before the lookup the scalar is shaped
     * to the enum's backing type by the same narrow rules the int and string parameters follow — an int-backed
     * enum takes exactly what an int parameter takes (emulated prepares hand '3' back for an INT column, and
     * under strict_types tryFrom('3') on an int-backed enum is a TypeError, not a lookup), a string-backed enum
     * takes a string or an int — so that the only way out of here is the enum's case or the named mismatch. The
     * backing type is read off the first case, which keeps the hydrator reflection-free; an enum with no
     * cases can match nothing and is a mismatch outright.
     *
     * @param  class-string<BackedEnum>  $type
     * @param  ProjectionParameterRow  $parameter
     */
    private function enum(mixed $value, string $type, array $parameter): BackedEnum
    {
        $cases = $type::cases();
        if ($cases === []) {
            $this->mismatch($value, $parameter);
        }

        $scalar = match (true) {
            is_int($cases[0]->value) => $this->int($value, $parameter),
            is_string($value) => $value,
            is_int($value) => (string) $value,
            default => $this->mismatch($value, $parameter),
        };

        return $type::tryFrom($scalar) ?? $this->mismatch($value, $parameter);
    }

    /**
     * A date-time is parsed from its string the way PHP parses it, with two exceptions turned into the named
     * mismatch: a string the parser rejects ('not a date', '2026-13-45') would otherwise escape as a bare
     * DateMalformedStringException, and an empty or blank string — which PHP reads as "now" — would otherwise
     * hydrate the current instant into a row that has no date at all, the one conversion worse than a failure
     * for a read model. The parser's own exception rides along as `previous` so its position-and-character
     * detail is not lost.
     *
     * @template T of DateTimeImmutable|DateTime
     *
     * @param  class-string<T>  $class
     * @param  ProjectionParameterRow  $parameter
     * @return T
     */
    private function date(string $value, string $class, array $parameter): DateTimeInterface
    {
        if (trim($value) === '') {
            $this->mismatch($value, $parameter);
        }

        try {
            return new $class($value);
        } catch (DateMalformedStringException $e) {
            $this->mismatch($value, $parameter, $e);
        }
    }

    /**
     * The one sentence every coercion failure in this class ends in: the DTO, the column, what the column holds
     * (the type, and for a scalar the value itself, so '150.75' reads as the DECIMAL it is and 'bronze' as the
     * case the enum lacks), the parameter and its declared type.
     *
     * @param  ProjectionParameterRow  $parameter
     */
    private function mismatch(mixed $value, array $parameter, ?Throwable $previous = null): never
    {
        throw new ConfigurationException(
            sprintf(
                'Projection [%s]: column [%s] holds %s, which cannot become parameter $%s (%s).',
                $this->dto(),
                $parameter['column'],
                self::describe($value),
                $parameter['name'],
                $parameter['type'] ?? 'mixed',
            ),
            previous: $previous,
        );
    }

    /**
     * `string '150.75'`, `int 2`, `float 150.75`, `bool true`; a non-scalar is just its type. A long string is
     * cut at 64 characters so a TEXT column mapped onto the wrong parameter does not paste its body into the
     * exception.
     */
    private static function describe(mixed $value): string
    {
        $type = get_debug_type($value);

        if (! is_scalar($value)) {
            return $type;
        }

        if (is_string($value)) {
            $shown = mb_strlen($value) > 64 ? mb_substr($value, 0, 64).'…' : $value;

            return sprintf("%s '%s'", $type, $shown);
        }

        return sprintf('%s %s', $type, var_export($value, true));
    }
}

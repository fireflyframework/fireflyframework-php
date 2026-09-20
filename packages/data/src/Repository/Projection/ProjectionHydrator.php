<?php

declare(strict_types=1);

namespace Firefly\Data\Repository\Projection;

use BackedEnum;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Firefly\Data\Transaction\TransactionalManifest;
use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * Turns a database row into a #[Projection] DTO — REFLECTION-FREE. The DTO's constructor was reflected once,
 * at scan time, into the manifest's projection row (name, snake_case column, declared type, nullability,
 * whether a default exists); here that row drives `new $dto(...$named)`, a named-argument spread, so an
 * optional parameter whose column is absent simply takes its default and a missing REQUIRED column is a
 * ConfigurationException that names both the column and the parameter.
 *
 * Coercion is deliberately narrow — the scalar the driver hands back into the scalar the parameter declares,
 * a backed enum via from(), a date-time from its string — because a projection is a read model and a read
 * model with surprising conversions is worse than one that fails. Anything else is passed through for the
 * constructor to accept or reject.
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
            'int' => is_int($value) ? $value : (is_numeric($value) ? (int) $value : $this->mismatch($value, $parameter)),
            'float' => is_float($value) ? $value : (is_numeric($value) ? (float) $value : $this->mismatch($value, $parameter)),
            'string' => is_string($value) ? $value : (is_scalar($value) ? (string) $value : $this->mismatch($value, $parameter)),
            'bool' => is_bool($value) ? $value : $this->bool($value, $parameter),
            default => $this->object($value, $type, $parameter),
        };
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
            return is_int($value) || is_string($value) ? $type::from($value) : $this->mismatch($value, $parameter);
        }

        if ($type === DateTimeImmutable::class || $type === DateTimeInterface::class) {
            return match (true) {
                is_string($value) => new DateTimeImmutable($value),
                $value instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($value),
                default => $this->mismatch($value, $parameter),
            };
        }

        if ($type === DateTime::class) {
            return match (true) {
                is_string($value) => new DateTime($value),
                $value instanceof DateTimeInterface => DateTime::createFromInterface($value),
                default => $this->mismatch($value, $parameter),
            };
        }

        return $value;
    }

    /**
     * @param  ProjectionParameterRow  $parameter
     */
    private function mismatch(mixed $value, array $parameter): never
    {
        throw new ConfigurationException(sprintf(
            'Projection [%s]: column [%s] holds %s, which cannot become parameter $%s (%s).',
            $this->dto(),
            $parameter['column'],
            get_debug_type($value),
            $parameter['name'],
            $parameter['type'] ?? 'mixed',
        ));
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Data\Repository\Locking;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Opt-in optimistic locking on a `version` integer column. Overrides Eloquent's performUpdate to bump the version
 * and guard the UPDATE with `WHERE version = <loaded value>`; if that matches zero rows the row changed under us
 * and an OptimisticLockException is thrown. Insert is untouched (a new row starts at its default version).
 *
 * @mixin Model
 */
trait HasOptimisticLock
{
    public function optimisticLockColumn(): string
    {
        return 'version';
    }

    /**
     * Matches the parent Eloquent\Model::performUpdate signature exactly (param type Builder, no native return
     * type — the parent only documents `@return bool` in its PHPDoc, so this override mirrors that).
     *
     * @param  Builder<static>  $query
     * @return bool
     */
    protected function performUpdate(Builder $query)
    {
        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        $dirty = $this->getDirty();
        if ($dirty === []) {
            return true;
        }

        $column = $this->optimisticLockColumn();
        $expected = $this->originalVersion($column);

        $this->setAttribute($column, $expected + 1);
        $dirty[$column] = $expected + 1;

        $affected = $this->setKeysForSaveQuery($query)
            ->where($column, $expected)
            ->update($dirty);

        if ($affected === 0) {
            $this->setAttribute($column, $expected);

            throw new OptimisticLockException(static::class, $this->identifierForError(), $expected);
        }

        $this->syncChanges();
        $this->fireModelEvent('updated', false);

        return true;
    }

    /**
     * `getOriginal()` is declared `mixed` (any attribute may hold any scalar), so it's narrowed here rather than
     * cast outright: an already-int original passes through, a numeric original (e.g. a driver that hands back
     * integer columns as strings) is converted, and anything else (a fresh/never-loaded column) defaults to 0.
     */
    private function originalVersion(string $column): int
    {
        $original = $this->getOriginal($column);

        return match (true) {
            is_int($original) => $original,
            is_numeric($original) => (int) $original,
            default => 0,
        };
    }

    /**
     * `getKey()` is declared `mixed` (a primary key may be any attribute type); OptimisticLockException only wants
     * it for a human-readable message, so anything other than an int/string identity is reported as null.
     */
    private function identifierForError(): int|string|null
    {
        $key = $this->getKey();

        return is_int($key) || is_string($key) ? $key : null;
    }
}

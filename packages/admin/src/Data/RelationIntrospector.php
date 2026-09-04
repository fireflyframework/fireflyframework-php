<?php

declare(strict_types=1);

namespace Firefly\Admin\Data;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Finds the relations an Eloquent model declares, and the columns that join them.
 *
 * WHY IT INVOKES THE METHOD. A relation method's NAME tells you nothing and its return TYPE tells you only
 * the kind — `hasMany`, `belongsTo`. The columns are decided inside the call, by Eloquent's conventions or
 * by the arguments the author passed, and there is no way to read `order_id` out of `$this->hasMany(Line::class)`
 * without running it. So the method is called on a fresh, unsaved model, and the Relation object it returns
 * is asked. That builds a query builder and executes NOTHING: Eloquent defers the query until you call get()
 * or first(), neither of which happens here.
 *
 * WHICH METHODS ARE SAFE TO CALL, and this is the whole safety argument. Only a public, non-static method
 * with no required parameters whose DECLARED RETURN TYPE is a Relation subclass. The declared return type is
 * what makes it safe: a method announcing `: HasMany` is a relation definition by construction — it is the
 * shape Laravel's own IDE tooling, its `with()` validation and every static analyser already rely on — and
 * an accessor or a side-effecting method cannot claim it without lying about its own signature. Anything
 * without that annotation is left alone, which costs a relation on an unannotated legacy model and is the
 * right trade against calling arbitrary code on a page load.
 *
 * MorphTo IS REPORTED BUT NOT NAVIGABLE. Its other end is decided per ROW by a type column, so there is no
 * single related class and no single resource to link to. Showing it as a relation with no link is more
 * useful than hiding it: a reader learns the model is polymorphic, which is usually why the record in front
 * of them looks the way it does.
 *
 * Every step is wrapped: a model that cannot be constructed, a relation method that throws, an Eloquent
 * version whose accessor is named differently. The browser degrades to "no relations" rather than failing to
 * render a record — the same bargain the rest of this package makes.
 */
final class RelationIntrospector
{
    /** @var array<string, list<array{name: string, kind: string, related: string, column: string, target: string, toMany: bool}>> */
    private array $cache = [];

    /**
     * The relations $entityClass declares, before any of them are matched to a browsable resource.
     *
     * @return list<array{name: string, kind: string, related: string, column: string, target: string, toMany: bool}>
     */
    public function forEntity(string $entityClass): array
    {
        if (isset($this->cache[$entityClass])) {
            return $this->cache[$entityClass];
        }

        return $this->cache[$entityClass] = $this->discover($entityClass);
    }

    /**
     * @return list<array{name: string, kind: string, related: string, column: string, target: string, toMany: bool}>
     */
    private function discover(string $entityClass): array
    {
        if (! class_exists($entityClass) || ! is_a($entityClass, Model::class, true)) {
            return [];
        }

        try {
            $reflection = new ReflectionClass($entityClass);
            if ($reflection->isAbstract()) {
                return [];
            }
            $model = $reflection->newInstance();
        } catch (Throwable) {
            return [];
        }

        $relations = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (! $this->isRelationMethod($method)) {
                continue;
            }

            $relation = $this->describe($model, $method->getName());
            if ($relation !== null) {
                $relations[] = $relation;
            }
        }

        usort($relations, static fn (array $a, array $b): int => [$a['toMany'], $a['name']] <=> [$b['toMany'], $b['name']]);

        return $relations;
    }

    private function isRelationMethod(ReflectionMethod $method): bool
    {
        if ($method->isStatic() || $method->getNumberOfRequiredParameters() > 0 || $method->isConstructor()) {
            return false;
        }

        $type = $method->getReturnType();

        return $type instanceof ReflectionNamedType
            && ! $type->isBuiltin()
            && is_a($type->getName(), Relation::class, true);
    }

    /**
     * @return array{name: string, kind: string, related: string, column: string, target: string, toMany: bool}|null
     */
    private function describe(Model $model, string $name): ?array
    {
        try {
            /** @var mixed $relation */
            $relation = $model->{$name}();
        } catch (Throwable) {
            return null;
        }

        if (! $relation instanceof Relation) {
            return null;
        }

        $kind = class_basename($relation);

        try {
            // MorphTo first: it IS a BelongsTo subclass, and asking a MorphTo for its related class gives
            // whichever placeholder Eloquent happened to instantiate rather than a real answer.
            if ($relation instanceof MorphTo) {
                return ['name' => $name, 'kind' => $kind, 'related' => '', 'column' => $relation->getForeignKeyName(), 'target' => '', 'toMany' => false];
            }

            $related = $relation->getRelated()::class;

            if ($relation instanceof BelongsTo) {
                // The key is on THIS row and points at the other table.
                return ['name' => $name, 'kind' => $kind, 'related' => $related, 'column' => $relation->getForeignKeyName(), 'target' => $relation->getOwnerKeyName(), 'toMany' => false];
            }

            if ($relation instanceof HasOneOrMany) {
                // The key is on the OTHER table and points back at this row, which is what makes "the lines
                // of order 7" a filter on the child listing rather than a lookup on this one.
                return ['name' => $name, 'kind' => $kind, 'related' => $related, 'column' => $this->tail($relation->getForeignKeyName()), 'target' => $relation->getLocalKeyName(), 'toMany' => true];
            }

            if ($relation instanceof BelongsToMany) {
                // The join lives in a pivot table, so neither side carries a column the browser can filter
                // on. Reported for its shape, not as a link.
                return ['name' => $name, 'kind' => $kind, 'related' => $related, 'column' => '', 'target' => '', 'toMany' => true];
            }

            // HasManyThrough and the morph-many family: a real relation whose join this browser cannot
            // express as one column comparison. Named, counted as to-many, not linked.
            return ['name' => $name, 'kind' => $kind, 'related' => $related, 'column' => '', 'target' => '', 'toMany' => true];
        } catch (Throwable) {
            return null;
        }
    }

    /** Eloquent qualifies a child key as `table.column`; the browser filters on the bare column. */
    private function tail(string $key): string
    {
        return str_contains($key, '.') ? substr($key, strrpos($key, '.') + 1) : $key;
    }
}

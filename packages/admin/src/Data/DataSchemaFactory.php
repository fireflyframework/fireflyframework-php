<?php

declare(strict_types=1);

namespace Firefly\Admin\Data;

use Firefly\Actuator\Introspection\SensitiveValueMasker;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Derives the displayable field list of a resource, honestly, from whichever source can actually answer.
 *
 * NEVER "dump every attribute". The tempting implementation is `$model->getAttributes()` on the first row and
 * use its keys as the columns, and it is wrong in three ways that all bite in production: an empty table
 * yields no columns at all (so the page renders as broken rather than as empty), a row that was hydrated with
 * a `select` of two columns yields two columns for the whole resource, and an accessor-heavy model yields
 * whatever `$appends` decided rather than what the table holds. Columns are a property of the RESOURCE, so
 * they are derived once from a source that describes the resource: the live schema for a model-backed one,
 * the entity's own declared fields for everything else.
 *
 * THE SCHEMA IS AUTHORITATIVE, THE CASTS REFINE IT. `Schema::getColumns()` reports what the driver knows,
 * and the driver frequently does not know what the application meant: sqlite stores a `json()` column as
 * `text` and a `boolean()` column as `tinyint`, so a type map built from `type_name` alone shows a JSON blob
 * as a string and a flag as a number. The model's own `$casts` carry the semantic type the schema cannot
 * express, so they are applied on top — `meta => array` makes `meta` render as JSON on every driver, not just
 * the ones whose type names happen to be self-describing. Where the two disagree the cast wins, because the
 * cast is what the application will hand the view.
 *
 * THE IDENTIFIER IS DERIVED, NOT ASSUMED. Eloquent knows its own key (`getKeyName()`, which respects a model
 * that renamed it); a plain entity is searched for a conventional identifier in a fixed order. It is allowed
 * to come back null, and everything downstream refuses rather than guessing — see DataSchema.
 *
 * A MODEL'S OWN `$hidden` IS TREATED AS SENSITIVE. `SensitiveValueMasker` decides by column NAME, which
 * catches `password`, `api_token` and their relatives but cannot know that this application considers
 * `recovery_phrase` a secret. A model that already hid a field from its JSON representation has stated that
 * intent in the only place it could, so the browser honours it as a second sensitivity source rather than
 * publishing in HTML what the model refuses to publish in JSON.
 */
final class DataSchemaFactory
{
    /** @var array<string, DataSchema> */
    private array $cache = [];

    public function __construct(private readonly RepositoryIntrospector $introspector) {}

    public function for(DataResource $resource): DataSchema
    {
        return $this->cache[$resource->slug] ??= $this->derive($resource);
    }

    private function derive(DataResource $resource): DataSchema
    {
        $entity = $resource->entityClass;
        if ($entity === null) {
            return DataSchema::empty();
        }

        return $resource->isEloquentBacked() && is_a($entity, Model::class, true)
            ? $this->fromModel($entity)
            : $this->fromEntity($entity);
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function fromModel(string $modelClass): DataSchema
    {
        try {
            $model = new $modelClass;
            $key = $model->getKeyName();
        } catch (Throwable) {
            return DataSchema::empty();
        }

        try {
            $rows = $model->getConnection()->getSchemaBuilder()->getColumns($model->getTable());
        } catch (Throwable) {
            // No usable connection (or no such table). The key name is still known and is the one column
            // every other operation needs, so the resource degrades to a key-only listing instead of
            // vanishing — and `source` says none, so the view can explain why the row is so bare.
            return new DataSchema([DataColumn::of($key, DataColumn::TYPE_STRING, false, true)], $key, DataSchema::SOURCE_NONE);
        }

        $casts = $model->getCasts();
        $hidden = $model->getHidden();

        $columns = [];
        foreach ($rows as $row) {
            $name = $row['name'];

            $columns[] = new DataColumn(
                name: $name,
                type: $this->castType($casts[$name] ?? null) ?? $this->columnType($row['type_name'], $row['type']),
                nullable: $row['nullable'],
                identifier: $name === $key,
                sensitive: SensitiveValueMasker::isSensitive($name) || in_array($name, $hidden, true),
            );
        }

        if ($columns === []) {
            return new DataSchema([DataColumn::of($key, DataColumn::TYPE_STRING, false, true)], $key, DataSchema::SOURCE_NONE);
        }

        $identifier = null;
        foreach ($columns as $column) {
            if ($column->identifier) {
                $identifier = $column->name;
            }
        }

        return new DataSchema($columns, $identifier, DataSchema::SOURCE_SCHEMA);
    }

    /**
     * @param  class-string  $entityClass
     */
    private function fromEntity(string $entityClass): DataSchema
    {
        $fields = $this->introspector->fieldsOf($entityClass);
        if ($fields === []) {
            return DataSchema::empty();
        }

        $identifier = $this->identifierOf($entityClass, array_column($fields, 'name'));

        $columns = [];
        foreach ($fields as $field) {
            $columns[] = DataColumn::of($field['name'], $field['type'], $field['nullable'], $field['name'] === $identifier);
        }

        return new DataSchema($columns, $identifier, DataSchema::SOURCE_ENTITY);
    }

    /**
     * The conventional identifier of a plain entity, in a FIXED preference order so the answer never depends
     * on declaration order: `id` (what Firefly's own Domain\Entity promotes), then `uuid`, then the
     * type-qualified forms an application writes when it avoids a bare `id` (`walletId`, `wallet_id`).
     * Nothing else is guessed — an entity that names its key something else gets a null identifier and a
     * list-only resource, which is a correct refusal rather than a delete aimed at the wrong column.
     *
     * @param  class-string  $entityClass
     * @param  list<string>  $names
     */
    private function identifierOf(string $entityClass, array $names): ?string
    {
        $position = strrpos($entityClass, '\\');
        $short = $position === false ? $entityClass : substr($entityClass, $position + 1);
        $snake = strtolower((string) preg_replace('/([a-z\d])([A-Z])/', '$1_$2', $short));

        foreach (['id', 'uuid', lcfirst($short).'Id', $snake.'_id'] as $candidate) {
            if (in_array($candidate, $names, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The semantic type a model's `$casts` entry declares, or null when the cast says nothing about display.
     *
     * Numeric casts (`float`, `double`, `decimal:2`) deliberately resolve to `string` rather than to a
     * numeric display type — see DataColumn for the money-rounding argument.
     */
    private function castType(mixed $cast): ?string
    {
        if (! is_string($cast)) {
            return null;
        }

        $base = strtolower(explode(':', $cast, 2)[0]);

        return match ($base) {
            'array', 'json', 'object', 'collection', 'encrypted' => DataColumn::TYPE_JSON,
            'bool', 'boolean' => DataColumn::TYPE_BOOL,
            'int', 'integer' => DataColumn::TYPE_INT,
            'date', 'datetime', 'immutable_date', 'immutable_datetime', 'custom_datetime',
            'immutable_custom_datetime', 'timestamp' => DataColumn::TYPE_DATETIME,
            'real', 'float', 'double', 'decimal', 'string' => DataColumn::TYPE_STRING,
            default => null,
        };
    }

    /**
     * Map a driver type name onto the display vocabulary.
     *
     * `tinyint` is checked against the FULL type rather than the type name because `tinyint(1)` is how both
     * MySQL and sqlite spell a boolean while a bare `tinyint` is a small integer, and the distinction is only
     * in the width.
     */
    private function columnType(string $typeName, string $fullType): string
    {
        $name = strtolower($typeName);
        $full = strtolower($fullType);

        if ($name === 'tinyint' || $name === 'bit') {
            return str_contains($full, '(1)') ? DataColumn::TYPE_BOOL : DataColumn::TYPE_INT;
        }

        return match ($name) {
            'bool', 'boolean' => DataColumn::TYPE_BOOL,
            'int', 'integer', 'bigint', 'smallint', 'mediumint', 'int2', 'int4', 'int8',
            'serial', 'bigserial', 'smallserial' => DataColumn::TYPE_INT,
            'json', 'jsonb' => DataColumn::TYPE_JSON,
            'date', 'datetime', 'datetime2', 'smalldatetime', 'datetimeoffset',
            'timestamp', 'timestamptz', 'datetimetz' => DataColumn::TYPE_DATETIME,
            default => DataColumn::TYPE_STRING,
        };
    }
}

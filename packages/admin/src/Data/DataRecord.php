<?php

declare(strict_types=1);

namespace Firefly\Admin\Data;

/**
 * One record, as an ORDERED field map — schema column order, not hash order.
 *
 * Order matters more than it looks. `getAttributes()` on an Eloquent model returns keys in whatever order the
 * driver returned them, which differs between drivers and can differ between two rows of the same table after
 * a migration adds a column. A detail page whose fields move between rows is unreadable, so the record is
 * projected against the schema's column list and inherits its order, and a column the row did not supply is
 * present with a null rather than missing.
 *
 * `rows()` is the shape the detail view actually wants: value plus the metadata needed to decide how to draw
 * it and whether to offer an edit. It is computed here rather than in the view so that the editability rule
 * (never the identifier, never a secret — see DataColumn::isEditable()) has exactly one definition.
 */
final readonly class DataRecord
{
    /**
     * @param  array<string, mixed>  $fields  masked, in schema column order
     */
    public function __construct(
        public DataResource $resource,
        public DataSchema $schema,
        public int|string $id,
        public array $fields,
    ) {}

    /**
     * @return list<array{name: string, label: string, type: string, nullable: bool, identifier: bool, sensitive: bool, editable: bool, value: mixed}>
     */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->schema->columns as $column) {
            $rows[] = [
                'name' => $column->name,
                'label' => $column->label(),
                'type' => $column->type,
                'nullable' => $column->nullable,
                'identifier' => $column->identifier,
                'sensitive' => $column->sensitive,
                'editable' => $column->isEditable(),
                'value' => $this->fields[$column->name] ?? null,
            ];
        }

        return $rows;
    }
}

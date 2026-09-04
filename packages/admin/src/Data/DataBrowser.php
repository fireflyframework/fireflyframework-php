<?php

declare(strict_types=1);

namespace Firefly\Admin\Data;

use Firefly\Actuator\Introspection\BeansCatalog;
use Firefly\Config\Config;
use Firefly\Data\Repository\CrudRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * The database browser's single entry point: discovery, schema, reads and the two writes, behind the gates.
 *
 * WHY THERE IS NO `create()`, AND WHY THAT IS NOT AN OMISSION TO BE FILLED IN LATER. A generic create form
 * over an arbitrary entity is a promise the browser cannot keep. An aggregate's constructor is where its
 * invariants live — an Order that must have at least one line, a Wallet whose balance starts at zero in the
 * currency it was opened in, a value object that rejects a malformed IBAN — and a form built from a column
 * list knows none of them. There are only two ways to build the row: call the constructor, which needs
 * arguments the form cannot supply in the right types or the right order and will fail on the first entity
 * with a non-trivial signature; or write the columns straight to the table, which produces a row the domain
 * model considers impossible and which every later read then has to cope with. The second is what a "just
 * insert the columns" implementation actually does, and it is worse than having no button, because it looks
 * like it worked. Creation belongs to the application's own code, where the constructor is. `update()` is
 * offered because it operates on a row that ALREADY satisfies its invariants and changes named columns on it;
 * `delete()` because removal needs no invariant at all.
 *
 * EVERY OPERATION IS GATED TWICE — once by `firefly.admin.data.enabled` and, for writes, again by
 * `firefly.admin.data.writable`, both default false. See DataBrowserSettings for the argument about why this
 * page does not inherit the dashboard's `app.debug` default: beans and config are facts about the
 * application, and these are facts about its users.
 *
 * NOTHING HERE THROWS AT THE CALLER. Reads answer with a DataListing that carries a reason, or a null record;
 * writes answer with a DataWriteResult carrying one of four outcomes. A view rendering an admin page must not
 * have to be exception-safe to stay on its feet, and — more sharply — an exception that escaped would be
 * rendered by the framework's error page, which on a QueryException means the SQL and its bindings on screen.
 */
final class DataBrowser
{
    private const string DISABLED = 'The database browser is disabled. Set firefly.admin.data.enabled to switch it on.';

    private const string UNRESOLVABLE = 'The repository bean for this resource could not be resolved from the container.';

    private const string NO_IDENTIFIER = 'This resource has no identifier column, so a single record cannot be addressed.';

    public function __construct(
        private readonly DataBrowserSettings $settings,
        private readonly DataResourceRegistry $registry,
        private readonly DataSchemaFactory $schemas,
        private readonly DataQueryEngine $engine,
        private readonly Container $container,
        private readonly RelationIntrospector $relations = new RelationIntrospector,
    ) {}

    /**
     * Assemble a browser from the application container — the one-liner a route or a view can call.
     *
     * BeansCatalog is optional because it is bound by ActuatorRouteRegistrar only when
     * `firefly.management.enabled` is on. Without it there is no discovery source and the browser reports no
     * resources, which is the correct degradation: the dashboard that hosts this page already requires the
     * actuator, so in every deployment that can reach this code the catalogue is there.
     */
    public static function forContainer(Container $container, ?Config $config = null): self
    {
        $config ??= new Config(self::configRepository($container));
        $settings = DataBrowserSettings::fromConfig($config);
        $introspector = new RepositoryIntrospector;

        $catalog = null;
        if ($container->bound(BeansCatalog::class)) {
            try {
                $catalog = $container->make(BeansCatalog::class);
            } catch (Throwable) {
                $catalog = null;
            }
        }

        return new self(
            $settings,
            new DataResourceRegistry($catalog, $introspector, $settings),
            new DataSchemaFactory($introspector),
            new DataQueryEngine($introspector),
            $container,
        );
    }

    public function settings(): DataBrowserSettings
    {
        return $this->settings;
    }

    public function isEnabled(): bool
    {
        return $this->settings->enabled;
    }

    /** True only when BOTH gates are open — the browser is on and writes are permitted. */
    public function isWritable(): bool
    {
        return $this->settings->canWrite();
    }

    /**
     * Every browsable resource, or an empty list when the browser is switched off.
     *
     * @return list<DataResource>
     */
    public function resources(): array
    {
        return $this->settings->enabled ? $this->registry->all() : [];
    }

    public function resource(string $slug): ?DataResource
    {
        return $this->settings->enabled ? $this->registry->get($slug) : null;
    }

    public function schema(string $slug): ?DataSchema
    {
        $resource = $this->resource($slug);

        return $resource === null ? null : $this->schemas->for($resource);
    }

    /**
     * One page of a resource.
     *
     * `$perPage` is null to mean "the configured default" and is clamped to `firefly.admin.data.max-page-size`
     * in every case, so a caller-supplied page size can never ask the fallback path to materialise a table.
     * `$page` is 1-based and floored at 1.
     */
    public function list(
        string $slug,
        int $page = 1,
        ?int $perPage = null,
        ?string $sort = null,
        string $direction = 'asc',
        ?string $search = null,
        ?DataFilter $filter = null,
    ): DataListing {
        $perPage = $this->settings->clampPageSize($perPage);
        $page = max(1, $page);

        if (! $this->settings->enabled) {
            return DataListing::failure(self::DISABLED, null, null, $page, $perPage);
        }

        $resource = $this->registry->get($slug);
        if ($resource === null) {
            return DataListing::failure('No such resource.', null, null, $page, $perPage);
        }

        $schema = $this->schemas->for($resource);
        $repository = $this->repositoryFor($resource);
        if ($repository === null) {
            return DataListing::failure(self::UNRESOLVABLE, $resource, $schema, $page, $perPage);
        }

        // A filter naming a column the resource does not have is DROPPED rather than passed to the
        // database. The column arrives in a URL an operator can hand-edit, and a query that reached the
        // driver with an arbitrary identifier in it is a column-name oracle at best.
        if ($filter !== null && ! in_array($filter->column, array_map(static fn (DataColumn $c): string => $c->name, $schema->columns), true)) {
            $filter = null;
        }

        return $this->engine->list($repository, $resource, $schema, $page, $perPage, $sort, $direction, $search, $filter);
    }

    /**
     * The relations this resource's entity declares, each already matched to a browsable resource where one
     * exists.
     *
     * MATCHING HAPPENS HERE and not in RelationIntrospector because it needs the REGISTRY: whether the other
     * end of a relation is browsable depends on whether some repository declares it and whether that
     * resource is excluded, neither of which is a fact about the model. Keeping the two apart means the
     * introspector answers "what does this model relate to" once per class, and this method answers "and can
     * I open it" against whatever the registry currently offers.
     *
     * @return list<DataRelation>
     */
    public function relationsFor(string $slug): array
    {
        if (! $this->settings->enabled || ! $this->settings->relations) {
            return [];
        }

        $resource = $this->registry->get($slug);
        if ($resource === null || $resource->entityClass === null) {
            return [];
        }

        $bySlugForClass = [];
        foreach ($this->registry->all() as $candidate) {
            if ($candidate->entityClass !== null && ! isset($bySlugForClass[$candidate->entityClass])) {
                $bySlugForClass[$candidate->entityClass] = $candidate->slug;
            }
        }

        $relations = [];
        foreach ($this->relations->forEntity($resource->entityClass) as $found) {
            $relations[] = new DataRelation(
                name: $found['name'],
                label: $this->humanise($found['name']),
                kind: $found['kind'],
                relatedClass: $found['related'],
                relatedSlug: $bySlugForClass[$found['related']] ?? null,
                column: $found['column'],
                target: $found['target'],
                toMany: $found['toMany'],
            );
        }

        return $relations;
    }

    private function humanise(string $name): string
    {
        $spaced = trim((string) preg_replace('/(?<!^)[A-Z]/', ' $0', str_replace('_', ' ', $name)));

        return ucfirst(strtolower($spaced));
    }

    /**
     * One record, or null when it cannot be shown — see DataQueryEngine::find() for why the null is
     * deliberately ambiguous.
     */
    public function find(string $slug, int|string $id): ?DataRecord
    {
        if (! $this->settings->enabled) {
            return null;
        }

        $resource = $this->registry->get($slug);
        if ($resource === null) {
            return null;
        }

        $repository = $this->repositoryFor($resource);

        return $repository === null
            ? null
            : $this->engine->find($repository, $resource, $this->schemas->for($resource), $id);
    }

    /**
     * Remove one row, addressed by the schema's identifier.
     *
     * The removal is verified after the fact with `existsById()` rather than trusted, because
     * `CrudRepository::deleteById()` returns void: a repository whose delete was a no-op (a soft-delete scope
     * that excluded the row, an override that swallowed it) would otherwise report success and the operator
     * would watch the row reappear on the next page load.
     */
    public function delete(string $slug, int|string $id): DataWriteResult
    {
        $refusal = $this->refuseWrite($slug, $id);
        if ($refusal !== null) {
            return $refusal;
        }

        $resource = $this->registry->get($slug);
        if ($resource === null) {
            return DataWriteResult::notFound('No such resource.', $slug, $id);
        }

        $schema = $this->schemas->for($resource);
        if ($schema->identifier === null) {
            return DataWriteResult::refused(self::NO_IDENTIFIER, $slug, $id);
        }

        $repository = $this->repositoryFor($resource);
        if ($repository === null) {
            return DataWriteResult::failed(self::UNRESOLVABLE, $slug, $id);
        }

        try {
            if (! $repository->existsById($id)) {
                return DataWriteResult::notFound('No such record.', $slug, $id);
            }

            $repository->deleteById($id);

            if ($repository->existsById($id)) {
                return DataWriteResult::failed('The repository accepted the delete but the record is still present.', $slug, $id);
            }
        } catch (Throwable $e) {
            return DataWriteResult::failed($this->engine->safeReason('The delete failed', $e), $slug, $id);
        }

        return DataWriteResult::done('Deleted.', $slug, $id);
    }

    /**
     * Write named columns onto one existing row, through the repository's own `save()`.
     *
     * WHY `save()` AND NOT AN UPDATE QUERY. Going straight to the builder would be one line and would bypass
     * everything the application attached to persistence: auditing (`created_by`/`updated_by`), optimistic
     * locking, the aggregate tracker that dispatches domain events after commit. An admin edit that silently
     * skips the audit trail is precisely the edit you most want audited.
     *
     * WHY ONLY ELOQUENT-BACKED RESOURCES. Mutating a plain entity means either calling setters the browser
     * cannot know about or reflecting values into promoted `readonly` properties, which is exactly the
     * invariant-bypassing that `create()` is refused for (see the class docblock) — with the additional
     * problem that on a readonly property it is not even possible. A resource whose entities are value
     * objects is browsable and deletable, and its edit is refused with a reason.
     *
     * THE IDENTIFIER AND MASKED COLUMNS ARE DROPPED, NOT REJECTED. A detail form legitimately round-trips
     * every field it rendered, including the key it addressed the row by and any column shown as `******`.
     * Rejecting the whole submission for containing them would make the obvious form implementation fail
     * every time; writing them would re-key the row, or overwrite a real credential with the mask. So they
     * are dropped, and `DataWriteResult::$changed` reports exactly which columns were written — silence with
     * a receipt, not silence.
     *
     * An UNKNOWN column, by contrast, is a hard refusal: it cannot come from a form this schema produced, so
     * it is either tampering or a bug, and quietly ignoring it would hide both.
     *
     * `$fields` is keyed by `array-key`, not by `string`, because that is what request input actually is:
     * PHP normalises a numeric form field name to an INTEGER key, so a POST containing `0=x` produces an int
     * key no matter how the form was meant to be built. Declaring the honest type is what lets the unknown-
     * column check reject it as data instead of raising a TypeError on the way in.
     *
     * @param  array<array-key, mixed>  $fields  column name => submitted value
     */
    public function update(string $slug, int|string $id, array $fields): DataWriteResult
    {
        $refusal = $this->refuseWrite($slug, $id);
        if ($refusal !== null) {
            return $refusal;
        }

        $resource = $this->registry->get($slug);
        if ($resource === null) {
            return DataWriteResult::notFound('No such resource.', $slug, $id);
        }

        $schema = $this->schemas->for($resource);
        if ($schema->identifier === null) {
            return DataWriteResult::refused(self::NO_IDENTIFIER, $slug, $id);
        }

        // The key type is int|string, not string: PHP turns a numeric form field name into an integer key,
        // so `<input name="0">` in a crafted POST would hand a `string` closure an int and raise a TypeError
        // under strict_types — a 500 from the one input this method exists to distrust.
        $unknown = array_values(array_filter(
            array_keys($fields),
            static fn (int|string $name): bool => ! $schema->has((string) $name),
        ));
        if ($unknown !== []) {
            return DataWriteResult::refused(
                sprintf('%d submitted field(s) are not columns of this resource.', count($unknown)),
                $slug,
                $id,
            );
        }

        $repository = $this->repositoryFor($resource);
        if ($repository === null) {
            return DataWriteResult::failed(self::UNRESOLVABLE, $slug, $id);
        }

        try {
            $entity = $repository->findById($id);
        } catch (Throwable $e) {
            return DataWriteResult::failed($this->engine->safeReason('The lookup failed', $e), $slug, $id);
        }

        if ($entity === null) {
            return DataWriteResult::notFound('No such record.', $slug, $id);
        }

        if (! $entity instanceof Model) {
            return DataWriteResult::refused(
                'This resource is not backed by an Eloquent model, so the browser will not mutate it — see DataBrowser::update().',
                $slug,
                $id,
            );
        }

        return $this->applyUpdate($repository, $entity, $schema, $slug, $id, $fields);
    }

    /**
     * @param  CrudRepository<object, mixed>  $repository
     * @param  array<array-key, mixed>  $fields
     */
    private function applyUpdate(
        CrudRepository $repository,
        Model $entity,
        DataSchema $schema,
        string $slug,
        int|string $id,
        array $fields,
    ): DataWriteResult {
        foreach ($fields as $name => $value) {
            $column = $schema->column((string) $name);
            if ($column === null || ! $column->isEditable()) {
                continue;
            }

            $coerced = $this->coerce($entity, $column, $value);
            if ($coerced === false) {
                return DataWriteResult::refused(
                    sprintf('The value submitted for "%s" is not a valid %s.', $column->name, $column->type),
                    $slug,
                    $id,
                );
            }

            $entity->setAttribute($column->name, $coerced[0]);
        }

        $changed = array_keys($entity->getDirty());
        if ($changed === []) {
            return DataWriteResult::done('Nothing changed.', $slug, $id);
        }

        try {
            $repository->save($entity);
        } catch (Throwable $e) {
            return DataWriteResult::failed($this->engine->safeReason('The update failed', $e), $slug, $id);
        }

        return DataWriteResult::done(sprintf('Updated %d field(s).', count($changed)), $slug, $id, $changed);
    }

    /**
     * Coerce one submitted value to the column's type, or report that it cannot be.
     *
     * Returns `false` for "invalid" and a ONE-ELEMENT ARRAY for "valid, here it is" — because the valid value
     * may itself legitimately be `null` or `false`, and a bare `?mixed` return cannot tell those apart from
     * failure.
     *
     * A form submits strings for everything, so `""` has to mean something. On a nullable non-string column it
     * means null (an emptied number field is not the integer zero); on a string column it means the empty
     * string, which is a real value that is not the same as null and must survive a round trip.
     *
     * @return array{0: mixed}|false
     */
    private function coerce(Model $entity, DataColumn $column, mixed $value): array|false
    {
        if ($value === null) {
            return $column->nullable ? [null] : false;
        }

        if (is_array($value)) {
            return $column->type === DataColumn::TYPE_JSON ? [$value] : false;
        }

        if (! is_scalar($value)) {
            return false;
        }

        $string = trim((string) $value);

        if ($string === '' && $column->type !== DataColumn::TYPE_STRING) {
            return $column->nullable ? [null] : false;
        }

        return match ($column->type) {
            DataColumn::TYPE_INT => preg_match('/^-?\d+$/', $string) === 1 ? [(int) $string] : false,
            // is_numeric rather than a regex: it already accepts every spelling a number field can produce
            // — a leading sign, a decimal point, exponent notation — and rejects the ones a decimal column
            // would otherwise silently store as 0.
            DataColumn::TYPE_FLOAT => is_numeric($string) ? [(float) $string] : false,
            DataColumn::TYPE_BOOL => $this->coerceBool($string),
            DataColumn::TYPE_DATETIME => strtotime($string) === false ? false : [$string],
            DataColumn::TYPE_JSON => $this->coerceJson($entity, $column, $string),
            default => [is_string($value) ? $value : $string],
        };
    }

    /** @return array{0: bool}|false */
    private function coerceBool(string $value): array|false
    {
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed === null ? false : [$parsed];
    }

    /**
     * JSON is validated before it is written — an admin form is the one place a malformed blob gets in by
     * hand, and a column that fails to decode on every subsequent read is a corruption that outlives the
     * session that caused it.
     *
     * WHAT gets written depends on the model, not on the column: with an `array`/`json`/`object`/`collection`
     * cast Eloquent will encode whatever it is given, so it must be handed the DECODED value or the row ends
     * up double-encoded (`"{\"a\":1}"`); with no cast the column is plain text and the submitted string is
     * exactly right.
     *
     * @return array{0: mixed}|false
     */
    private function coerceJson(Model $entity, DataColumn $column, string $value): array|false
    {
        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        return [$entity->hasCast($column->name, ['array', 'json', 'object', 'collection']) ? $decoded : $value];
    }

    /**
     * The gate check shared by both writes. Returns the refusal, or null when the caller may proceed.
     *
     * The two keys are reported separately rather than as one "not permitted": an operator who turned the
     * browser on and forgot the second key needs to be told which key, and an operator who never turned the
     * browser on at all should not be told that a write key exists.
     */
    private function refuseWrite(string $slug, int|string $id): ?DataWriteResult
    {
        if (! $this->settings->enabled) {
            return DataWriteResult::refused(self::DISABLED, $slug, $id);
        }

        if (! $this->settings->writable) {
            return DataWriteResult::refused(
                'The database browser is read-only. Set firefly.admin.data.writable to permit writes.',
                $slug,
                $id,
            );
        }

        return null;
    }

    /**
     * Resolve the repository bean once, for both the read and the write path.
     *
     * A resource came from the catalogue, so the BINDING exists — but resolving it runs a constructor, and a
     * constructor can fail for reasons that have nothing to do with this page (a connection this deployment
     * did not configure, a collaborator bean a condition backed off from). A null here becomes a stated
     * reason, never a 500.
     *
     * @return CrudRepository<object, mixed>|null
     */
    public function repositoryFor(DataResource $resource): ?CrudRepository
    {
        try {
            $bean = $this->container->make($resource->repositoryClass);
        } catch (Throwable) {
            return null;
        }

        return $bean instanceof CrudRepository ? $bean : null;
    }

    /**
     * The Illuminate config repository Firefly's typed Config wraps. `Firefly\Config\Config` is not itself a
     * container binding anywhere in the framework — every call site builds one over the repository — so this
     * mirrors what AdminRouteRegistrar does with `$context->config` rather than inventing a new binding the
     * boot pipeline does not create.
     */
    private static function configRepository(Container $container): ConfigRepository
    {
        return $container->make('config');
    }
}

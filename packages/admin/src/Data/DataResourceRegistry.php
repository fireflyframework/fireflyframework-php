<?php

declare(strict_types=1);

namespace Firefly\Admin\Data;

use Firefly\Actuator\Introspection\BeansCatalog;
use Firefly\Data\Repository\CrudRepository;
use Firefly\Data\Repository\EloquentRepository;
use Firefly\Data\Repository\PagingAndSortingRepository;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Finds the browsable resources of the RUNNING application: every bean whose scan-time interface list
 * contains CrudRepository.
 *
 * WHY THE COMPILED CATALOGUE AND NOT A FRESH SCAN. BeansCatalog is the boot-time snapshot of the
 * condition-filtered BeanDefinitionRegistry — the same rows /actuator/beans serves — and each row already
 * carries `class`, `stereotype` and the FULL interface closure ComponentScanner recorded with
 * `class_implements()` at scan time. So "is this bean a repository, and does it also page?" is answered by
 * two `in_array()` calls over data the process already holds. Re-deriving it by reflecting over every
 * registered class at request time would be slower, would break the framework's reflection-free boot
 * contract for no gain, and — the decisive point — would find classes the CONTAINER never registered, so the
 * menu would offer resources that cannot be resolved. The catalogue is the definition of "what this
 * application actually wired", which is exactly the question a browser is asking.
 *
 * The one fact the catalogue cannot answer is which model an EloquentRepository manages; that comes from
 * RepositoryIntrospector, the single reflecting class in this layer, and see its docblock for why.
 *
 * SLUGS ARE DERIVED FROM THE CLASS, NOT FROM A COUNTER. A slug ends up in a URL an operator bookmarks and in
 * a link another page renders, so it must not move because an unrelated repository was added. The base slug
 * is the kebab-cased short name of the entity (falling back to the repository's own name with the
 * conventional `Eloquent` prefix and `Repository` suffix stripped), which depends on nothing but that class.
 * A genuine collision — two `Wallet` entities in different namespaces — is resolved by qualifying BOTH sides
 * with their full namespace rather than by suffixing one of them with `-2`: an index suffix depends on scan
 * order, so the loser's URL would change if the winner were ever removed, whereas a namespace-qualified slug
 * is a property of the class alone. The label is disambiguated the same way, because a menu with two entries
 * both reading "Wallet" is not a menu.
 *
 * DISABLED MEANS EMPTY, HERE TOO. `all()` returns nothing unless `firefly.admin.data.enabled` is set, even
 * though DataBrowser gates every operation as well. The redundancy is the point: this object is public API
 * that a view could hold directly, and a discovery list that leaks the names of an application's entities
 * while the browser is switched off would already be a disclosure.
 */
final class DataResourceRegistry
{
    /** @var list<DataResource>|null */
    private ?array $resources = null;

    public function __construct(
        private readonly ?BeansCatalog $catalog,
        private readonly RepositoryIntrospector $introspector,
        private readonly DataBrowserSettings $settings,
    ) {}

    /**
     * Every browsable resource, ordered by label so the menu is stable across boots.
     *
     * @return list<DataResource>
     */
    public function all(): array
    {
        if ($this->resources !== null) {
            return $this->resources;
        }

        if (! $this->settings->enabled || $this->catalog === null) {
            return $this->resources = [];
        }

        $candidates = [];
        $seen = [];
        foreach ($this->catalog->all() as $bean) {
            $class = $bean['class'];
            if (isset($seen[$class]) || ! in_array(CrudRepository::class, $bean['interfaces'], true) || ! class_exists($class)) {
                continue;
            }

            $seen[$class] = true;
            $candidates[] = $this->describe($class, in_array(PagingAndSortingRepository::class, $bean['interfaces'], true));
        }

        $resources = [];
        foreach ($this->resolveSlugs($candidates) as $resource) {
            if ($this->settings->allows($resource->slug)) {
                $resources[] = $resource;
            }
        }

        usort($resources, static fn (DataResource $a, DataResource $b): int => [$a->label, $a->slug] <=> [$b->label, $b->slug]);

        return $this->resources = $resources;
    }

    public function get(string $slug): ?DataResource
    {
        foreach ($this->all() as $resource) {
            if ($resource->slug === $slug) {
                return $resource;
            }
        }

        return null;
    }

    /**
     * @param  class-string  $class
     * @return array{class: class-string, entity: class-string|null, table: string|null, paged: bool, eloquent: bool}
     */
    private function describe(string $class, bool $paged): array
    {
        $model = $this->introspector->modelOf($class);

        // Eloquent-backed means all three: the repository extends the Eloquent base, it declared a $model,
        // and that $model really is a Model. A repository that declares a `$model` pointing at something else
        // is not an error — it is just not schema-browsable, and it keeps the declared class as its entity.
        if ($model !== null && is_a($class, EloquentRepository::class, true) && is_a($model, Model::class, true)) {
            return ['class' => $class, 'entity' => $model, 'table' => $this->tableOf($model), 'paged' => $paged, 'eloquent' => true];
        }

        return [
            'class' => $class,
            'entity' => $model ?? $this->introspector->entityOf($class),
            'table' => null,
            'paged' => $paged,
            'eloquent' => false,
        ];
    }

    /**
     * The model's table name, from a bare instance.
     *
     * Constructing the model is safe and is what EloquentRepository itself does to read the key name: an
     * Eloquent constructor takes an optional attribute array and touches no connection. It is still guarded,
     * because a model with a hand-written constructor is legal and a discovery pass must not be able to fail
     * on one — the resource simply loses its table name and, with it, schema-derived columns.
     *
     * @param  class-string<Model>  $model
     */
    private function tableOf(string $model): ?string
    {
        try {
            return (new $model)->getTable();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Assign slugs and labels, qualifying every member of a colliding group rather than suffixing one.
     *
     * @param  list<array{class: class-string, entity: class-string|null, table: string|null, paged: bool, eloquent: bool}>  $candidates
     * @return list<DataResource>
     */
    private function resolveSlugs(array $candidates): array
    {
        $counts = [];
        foreach ($candidates as $candidate) {
            $base = self::baseSlug($candidate['entity'], $candidate['class']);
            $counts[$base] = ($counts[$base] ?? 0) + 1;
        }

        $resources = [];
        foreach ($candidates as $candidate) {
            $named = $candidate['entity'] ?? $candidate['class'];
            $base = self::baseSlug($candidate['entity'], $candidate['class']);
            $collides = ($counts[$base] ?? 0) > 1;

            $resources[] = new DataResource(
                slug: $collides ? self::qualifiedSlug($named) : $base,
                label: $collides ? self::label($base).' ('.self::namespaceOf($named).')' : self::label($base),
                repositoryClass: $candidate['class'],
                entityClass: $candidate['entity'],
                table: $candidate['table'],
                paged: $candidate['paged'],
                eloquent: $candidate['eloquent'],
            );
        }

        return $resources;
    }

    /**
     * @param  class-string|null  $entity
     * @param  class-string  $repository
     */
    private static function baseSlug(?string $entity, string $repository): string
    {
        if ($entity !== null) {
            return self::kebab(self::shortName($entity));
        }

        // No entity type to name the resource after, so name it after the repository with the two
        // conventions the framework's own sample uses stripped: EloquentWalletRepository => wallet.
        $short = self::shortName($repository);
        $short = preg_replace('/^Eloquent/', '', $short) ?? $short;
        $short = preg_replace('/Repository$/', '', $short) ?? $short;

        return self::kebab($short === '' ? self::shortName($repository) : $short);
    }

    private static function qualifiedSlug(string $class): string
    {
        $parts = array_map(self::kebab(...), explode('\\', trim($class, '\\')));

        return implode('-', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    private static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }

    private static function namespaceOf(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? '' : substr($class, 0, $position);
    }

    /** `OrderLine` => `order-line`, `APIKey` => `api-key`. */
    private static function kebab(string $name): string
    {
        $spaced = preg_replace(['/([a-z\d])([A-Z])/', '/([A-Z]+)([A-Z][a-z])/'], '$1-$2', $name) ?? $name;

        return strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $spaced));
    }

    /** `order-line` => `Order Line`. */
    private static function label(string $slug): string
    {
        return ucwords(str_replace('-', ' ', $slug));
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Data\Scanner;

use Firefly\Data\Proxy\ProxyMethod;
use Firefly\Data\Proxy\ProxyPlanner;
use Firefly\Data\Proxy\ProxySignature;
use Firefly\Data\Proxy\UnsupportedTransactionalMethodException;
use Firefly\Data\Repository\Attributes\EntityGraph;
use Firefly\Data\Repository\Attributes\Lock;
use Firefly\Data\Repository\Attributes\Modifying;
use Firefly\Data\Repository\Attributes\Projection;
use Firefly\Data\Repository\Attributes\Query;
use Firefly\Data\Repository\EloquentRepository;
use Firefly\Data\Repository\Page;
use Firefly\Data\Repository\Slice;
use Firefly\Data\Transaction\Attributes\Transactional;
use Firefly\Data\Transaction\Attributes\TransactionalEventListener;
use Firefly\Data\Transaction\TransactionalDescriptor;
use Firefly\Data\Transaction\TransactionalManifest;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

/**
 * The ONE scan-time reflection file in packages/data/src (grep invariant): it reflects class + method
 * #[Transactional] (a method-level attribute REPLACES class-level for that method — Spring semantics) and
 * #[Query] methods, emitting a pure-array TransactionalManifest. It also reflects the repository-method
 * attributes (#[Modifying]/#[Projection]/#[Lock]/#[EntityGraph], plus a Slice/Page return type) and every
 * #[TransactionalEventListener], and refuses the shapes that cannot work — a #[Modifying] without a #[Query] or
 * on a SELECT, a #[Lock] on a #[Query] — so a misuse fails at firefly:cache, not at the first request. Runs
 * only at cache time (firefly:cache) or inline in tests; production loads the compiled manifest via
 * require+map.
 *
 * @phpstan-import-type ProxyRow from TransactionalManifest
 * @phpstan-import-type QueryRow from TransactionalManifest
 * @phpstan-import-type RepositoryMethodRow from TransactionalManifest
 * @phpstan-import-type ProjectionRow from TransactionalManifest
 * @phpstan-import-type ListenerRow from TransactionalManifest
 */
final class TransactionalScanner
{
    /**
     * @param  array<string,string>  $psr4  namespace-prefix => absolute directory
     */
    public function scan(array $psr4): TransactionalManifest
    {
        /** @var array<class-string, ProxyRow> $proxies */
        $proxies = [];
        /** @var array<class-string, array<string, QueryRow>> $queries */
        $queries = [];
        /** @var array<class-string, array<string, RepositoryMethodRow>> $repositories */
        $repositories = [];
        /** @var list<ListenerRow> $listeners */
        $listeners = [];

        foreach ($this->classes($psr4) as $class) {
            $reflection = new ReflectionClass($class);
            $classAttr = $this->firstTransactional($reflection->getAttributes(Transactional::class));

            $methods = [];
            $classQueries = [];
            $classRows = [];

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if (! $this->proxyable($method)) {
                    continue;
                }

                $query = $this->firstQuery($method->getAttributes(Query::class));
                if ($query !== null) {
                    $classQueries[$method->getName()] = ['sql' => $query->sql, 'native' => $query->native];
                }

                $row = $this->repositoryMethodRow($method, $class, $query);
                if ($row !== null) {
                    $classRows[$method->getName()] = $row;
                }

                foreach ($method->getAttributes(TransactionalEventListener::class) as $attribute) {
                    $listener = $attribute->newInstance();
                    /** @var class-string $event */
                    $event = $listener->event ?? $this->inferEventType($method, $class);
                    $listeners[] = [
                        'class' => $class,
                        'method' => $method->getName(),
                        'event' => $event,
                        'phase' => $listener->phase->value,
                        'fallbackExecution' => $listener->fallbackExecution,
                        'order' => $listener->order,
                    ];
                }

                $effective = $this->firstTransactional($method->getAttributes(Transactional::class)) ?? $classAttr;
                if ($effective !== null) {
                    $methods[$method->getName()] = TransactionalDescriptor::fromAttribute($effective)->toArray();
                }
            }

            if ($methods !== []) {
                $proxies[$class] = ['proxyClass' => $class.'__FireflyTransactionalProxy', 'methods' => $methods];
            }

            if ($classQueries !== []) {
                $queries[$class] = $classQueries;
            }

            if ($classRows !== []) {
                ksort($classRows);
                $repositories[$class] = $classRows;
            }
        }

        usort($listeners, static fn (array $a, array $b): int => [$a['order'], $a['class'], $a['method']] <=> [$b['order'], $b['class'], $b['method']]);

        return new TransactionalManifest($proxies, $queries, $repositories, $listeners);
    }

    /**
     * Generation inputs for the ProxyClassGenerator from the #[Transactional] attributes alone — the shape the
     * proxy tests, the capstone fixtures and (until it compiles proxy-plan.php) firefly:cache's writeProxies()
     * drive. It is a transactional-only ProxyPlan rendered back into ProxyMethods; the uncached boot goes
     * through ProxyPlanner with every AdviceSource instead.
     *
     * @param  array<string,string>  $psr4
     * @return array<class-string, array<string, ProxyMethod>>
     */
    public function scanProxyMethods(array $psr4): array
    {
        $planner = ProxyPlanner::transactionalOnly();

        return $planner->proxyMethods($planner->plan($psr4));
    }

    /**
     * The rendered signatures of the named public methods of one class — the ONLY place signatures are
     * reflected, keeping ProxyClassGenerator and ProxyPlanner free of the reflection substrings.
     *
     * Two things fail loud here rather than inside a generated class: a `final` target (the proxy must extend
     * it, and PHP would fatal at require time with no hint of which manifest row caused it) and a by-reference
     * parameter (`&$out`: the terminal closure spreads a copied list, so the writeback would be silently lost).
     *
     * @param  class-string  $class
     * @param  list<string>  $methods
     * @return array<string, ProxySignature>
     */
    public function signatures(string $class, array $methods): array
    {
        $reflection = new ReflectionClass($class);
        if ($reflection->isFinal()) {
            throw UnsupportedTransactionalMethodException::finalClass($class);
        }

        $signatures = [];
        foreach ($methods as $name) {
            $method = $reflection->getMethod($name);
            [$paramSource, $argSource] = $this->renderParameters($method, $class);
            $signatures[$name] = new ProxySignature($paramSource, $argSource, $this->renderReturnType($method));
        }

        return $signatures;
    }

    /**
     * By-reference parameters FAIL LOUD here (compile-time): the generated override would wrap the call in
     * `fn () => parent::m($p)`, an arrow closure that captures `$p` BY VALUE, so a by-ref writeback would be
     * silently dropped. isPassedByReference() is a ReflectionParameter method, so this detection MUST stay in
     * this sanctioned scanner (the generator/exception remain reflection-free).
     *
     * @param  class-string  $class
     * @return array{0: string, 1: string} [paramSource, argSource]
     */
    private function renderParameters(ReflectionMethod $method, string $class): array
    {
        $params = [];
        $args = [];

        foreach ($method->getParameters() as $parameter) {
            if ($parameter->isPassedByReference()) {
                throw UnsupportedTransactionalMethodException::byReferenceParameter($class, $method->getName(), $parameter->getName());
            }

            $piece = $this->renderType($parameter->getType());
            $piece = $piece === '' ? '' : $piece.' ';
            $piece .= $parameter->isVariadic() ? '...' : '';
            $piece .= '$'.$parameter->getName();

            if ($parameter->isDefaultValueAvailable() && ! $parameter->isVariadic()) {
                $piece .= ' = '.$this->renderDefault($parameter);
            }

            $params[] = $piece;
            $args[] = ($parameter->isVariadic() ? '...' : '').'$'.$parameter->getName();
        }

        return [implode(', ', $params), implode(', ', $args)];
    }

    private function renderReturnType(ReflectionMethod $method): string
    {
        $rendered = $this->renderType($method->getReturnType());

        return $rendered === '' ? '' : ': '.$rendered;
    }

    private function renderType(?ReflectionType $type): string
    {
        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();
            if (in_array($name, ['self', 'static', 'parent', 'mixed', 'void', 'never', 'null', 'false', 'true'], true)) {
                $prefix = $type->allowsNull() && ! in_array($name, ['mixed', 'null'], true) ? '?' : '';

                return $prefix.$name;
            }

            $prefix = $type->allowsNull() ? '?' : '';

            return $prefix.($type->isBuiltin() ? $name : '\\'.ltrim($name, '\\'));
        }

        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map(fn (ReflectionType $t): string => $this->renderType($t), $type->getTypes()));
        }

        if ($type instanceof ReflectionIntersectionType) {
            return implode('&', array_map(fn (ReflectionType $t): string => $this->renderType($t), $type->getTypes()));
        }

        return '';
    }

    private function renderDefault(ReflectionParameter $parameter): string
    {
        if ($parameter->isDefaultValueConstant()) {
            // Global/class constants render verbatim; self::/static:: constants are a documented latent edge.
            return (string) $parameter->getDefaultValueConstantName();
        }

        // var_export handles scalars, arrays, null and enum cases (PHP 8.1+).
        return var_export($parameter->getDefaultValue(), true);
    }

    private function proxyable(ReflectionMethod $method): bool
    {
        return ! $method->isStatic()
            && ! $method->isConstructor()
            && ! $method->isDestructor()
            && ! str_starts_with($method->getName(), '__');
    }

    /**
     * @param  array<int, ReflectionAttribute<Transactional>>  $attributes
     */
    private function firstTransactional(array $attributes): ?Transactional
    {
        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    /**
     * @param  array<int, ReflectionAttribute<Query>>  $attributes
     */
    private function firstQuery(array $attributes): ?Query
    {
        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    /**
     * The repository-method row for a method carrying any of the four attributes or a Slice/Page return type;
     * null for a plain method (the map stays sparse). The refusals live here so they fire at scan/compile time.
     *
     * @param  class-string  $class
     * @return RepositoryMethodRow|null
     */
    private function repositoryMethodRow(ReflectionMethod $method, string $class, ?Query $query): ?array
    {
        $modifying = $this->firstAttribute($method->getAttributes(Modifying::class));
        $projection = $this->firstAttribute($method->getAttributes(Projection::class));
        $lock = $this->firstAttribute($method->getAttributes(Lock::class));
        $graph = $this->firstAttribute($method->getAttributes(EntityGraph::class));
        $returns = $this->returnKind($method);

        if ($modifying === null && $projection === null && $lock === null && $graph === null && $returns === null) {
            return null;
        }

        $label = "{$class}::{$method->getName()}()";

        if ($modifying !== null) {
            if ($query === null) {
                throw new ConfigurationException("#[Modifying] on {$label} needs a #[Query]: a derived deleteBy… is already a statement, and an update needs its SQL.");
            }
            if (self::isSelect($query->sql)) {
                throw new ConfigurationException("#[Modifying] on {$label} carries a SELECT; a modifying query must be an UPDATE, DELETE or INSERT.");
            }
        }

        if ($lock !== null && $query !== null) {
            throw new ConfigurationException("#[Lock] on {$label} cannot be applied to a #[Query]; write the locking clause in the SQL itself.");
        }

        return [
            'modifying' => $modifying === null ? null : ['requiresTransaction' => $modifying->requiresTransaction],
            'projection' => $projection === null ? null : $this->projectionRow($projection, $label),
            'lock' => $lock?->mode->value,
            'entityGraph' => $graph === null ? null : ['value' => $graph->value, 'attributePaths' => $graph->attributePaths],
            'returns' => $returns,
        ];
    }

    /**
     * The DTO's constructor, reflected ONCE here: name, snake_case column, declared type, nullability, whether a
     * default exists. ProjectionHydrator consumes this row with `new $dto(...$named)` and no reflection.
     *
     * @return ProjectionRow
     */
    private function projectionRow(Projection $projection, string $label): array
    {
        if (! class_exists($projection->dto)) {
            throw new ConfigurationException("#[Projection] on {$label} names [{$projection->dto}], which is not a class.");
        }

        $constructor = (new ReflectionClass($projection->dto))->getConstructor();
        if ($constructor === null) {
            throw new ConfigurationException("#[Projection] on {$label}: [{$projection->dto}] has no constructor to hydrate through.");
        }

        $parameters = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type !== null && ! $type instanceof ReflectionNamedType) {
                throw new ConfigurationException("#[Projection] on {$label}: parameter \${$parameter->getName()} of [{$projection->dto}] has a union or intersection type, which a column cannot satisfy.");
            }

            $parameters[] = [
                'name' => $parameter->getName(),
                'column' => strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $parameter->getName())),
                'type' => $type?->getName(),
                'nullable' => $type === null || $type->allowsNull(),
                'optional' => $parameter->isDefaultValueAvailable(),
            ];
        }

        return [
            'dto' => $projection->dto,
            'columns' => $projection->columns !== [] ? $projection->columns : array_column($parameters, 'column'),
            'parameters' => $parameters,
        ];
    }

    /**
     * The paging kind a derived-query dispatch must build for this method. Only a method the application
     * declares counts: EloquentRepository's own reads (findPaged, findBySpecificationPaged, findByExamplePaged,
     * findSlice) declare Page/Slice for their callers and never reach dispatchQuery(), so they get no row.
     *
     * @return 'slice'|'page'|null
     */
    private function returnKind(ReflectionMethod $method): ?string
    {
        if ($method->getDeclaringClass()->getName() === EloquentRepository::class) {
            return null;
        }

        $type = $method->getReturnType();
        if (! $type instanceof ReflectionNamedType) {
            return null;
        }

        return match ($type->getName()) {
            Slice::class => 'slice',
            Page::class => 'page',
            default => null,
        };
    }

    /** SELECT / WITH / VALUES after any leading comments or parentheses is a query, never a statement. */
    private static function isSelect(string $sql): bool
    {
        $stripped = (string) preg_replace('~^(?:\s|/\*.*?\*/|--[^\n]*\n)*\(*\s*~s', '', $sql);

        return preg_match('/^(select|with|values)\b/i', $stripped) === 1;
    }

    private function inferEventType(ReflectionMethod $method, string $declaringClass): string
    {
        $parameters = $method->getParameters();

        if ($parameters === []) {
            throw new ConfigurationException(
                "#[TransactionalEventListener] on {$declaringClass}::{$method->getName()}() has no explicit event and no ".
                'parameters to infer one from.',
            );
        }

        $type = $parameters[0]->getType();

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
            return $type->getName();
        }

        throw new ConfigurationException(
            "#[TransactionalEventListener] on {$declaringClass}::{$method->getName()}() has no explicit event and its first ".
            'parameter has no inferable class type.',
        );
    }

    /**
     * @template T of object
     *
     * @param  array<int, ReflectionAttribute<T>>  $attributes
     * @return T|null
     */
    private function firstAttribute(array $attributes): ?object
    {
        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    /**
     * @param  array<string,string>  $psr4
     * @return list<class-string>
     */
    private function classes(array $psr4): array
    {
        $classes = [];

        foreach ($psr4 as $prefix => $dir) {
            $prefix = rtrim($prefix, '\\').'\\';
            if (! is_dir($dir)) {
                continue;
            }

            $realDir = rtrim((string) realpath($dir), DIRECTORY_SEPARATOR);
            /** @var iterable<\SplFileInfo> $files */
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($realDir, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = substr((string) $file->getRealPath(), strlen($realDir) + 1, -4);
                $class = $prefix.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
                if (! class_exists($class)) {
                    continue;
                }

                $reflection = new ReflectionClass($class);
                if ($reflection->isAbstract() || $reflection->isInterface()) {
                    continue;
                }

                /** @var class-string $class */
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }
}

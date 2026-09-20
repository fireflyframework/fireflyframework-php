<?php

declare(strict_types=1);

namespace Firefly\Data\Scanner;

use Firefly\Data\Proxy\ProxyMethod;
use Firefly\Data\Proxy\ProxyPlanner;
use Firefly\Data\Proxy\ProxySignature;
use Firefly\Data\Proxy\UnsupportedTransactionalMethodException;
use Firefly\Data\Repository\Attributes\Query;
use Firefly\Data\Transaction\Attributes\Transactional;
use Firefly\Data\Transaction\TransactionalDescriptor;
use Firefly\Data\Transaction\TransactionalManifest;
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
 * #[Query] methods, emitting a pure-array TransactionalManifest. Runs only at cache time (firefly:cache) or
 * inline in tests; production loads the compiled manifest via require+map.
 *
 * @phpstan-import-type ProxyRow from TransactionalManifest
 * @phpstan-import-type QueryRow from TransactionalManifest
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

        foreach ($this->classes($psr4) as $class) {
            $reflection = new ReflectionClass($class);
            $classAttr = $this->firstTransactional($reflection->getAttributes(Transactional::class));

            $methods = [];
            $classQueries = [];

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if (! $this->proxyable($method)) {
                    continue;
                }

                $query = $this->firstQuery($method->getAttributes(Query::class));
                if ($query !== null) {
                    $classQueries[$method->getName()] = ['sql' => $query->sql, 'native' => $query->native];
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
        }

        return new TransactionalManifest($proxies, $queries);
    }

    /**
     * Generation inputs for the ProxyClassGenerator from the #[Transactional] attributes alone — the shape the
     * proxy tests and the capstone fixtures drive. It is a transactional-only ProxyPlan rendered back into
     * ProxyMethods; firefly:cache and the uncached boot go through ProxyPlanner with every AdviceSource instead.
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

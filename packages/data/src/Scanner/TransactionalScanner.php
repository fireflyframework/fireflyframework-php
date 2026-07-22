<?php

declare(strict_types=1);

namespace Firefly\Data\Scanner;

use Firefly\Data\Repository\Attributes\Query;
use Firefly\Data\Transaction\Attributes\Transactional;
use Firefly\Data\Transaction\TransactionalDescriptor;
use Firefly\Data\Transaction\TransactionalManifest;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;

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

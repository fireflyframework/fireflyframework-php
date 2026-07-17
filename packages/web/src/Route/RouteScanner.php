<?php

declare(strict_types=1);

namespace Firefly\Web\Route;

use Firefly\Validation\Valid;
use Firefly\Web\Attributes\Mapping;
use Firefly\Web\Attributes\PathVariable;
use Firefly\Web\Attributes\QueryParam;
use Firefly\Web\Attributes\RequestBody;
use Firefly\Web\Attributes\RequestHeader;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;
use Firefly\Web\Attributes\UploadedFile;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * The ONE reflection file in packages/web/src (grep invariant). Scans #[RestController] classes, reads the
 * class-level #[RequestMapping] base path and each method's #[Mapping] (verb) attribute, and reflects each
 * method parameter into a pure-array binding plan. Runs only at cache time; production loads the compiled
 * RouteManifest.
 *
 * @phpstan-import-type Binding from RouteDescriptor
 */
final class RouteScanner
{
    /**
     * @param  array<string,string>  $psr4  namespace-prefix => absolute directory
     * @return list<RouteDescriptor>
     */
    public function scan(array $psr4): array
    {
        $descriptors = [];

        foreach ($this->controllerClasses($psr4) as $class) {
            $reflection = new ReflectionClass($class);
            $base = $this->basePath($reflection);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(Mapping::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                    $mapping = $attribute->newInstance();
                    $descriptors[] = new RouteDescriptor(
                        httpMethod: $mapping->method(),
                        path: $this->join($base, $mapping->path()),
                        controllerClass: $class,
                        methodName: $method->getName(),
                        status: $mapping->status(),
                        name: $mapping->name(),
                        bindings: $this->bindings($method),
                    );
                }
            }
        }

        return $descriptors;
    }

    /**
     * @param  array<string,string>  $psr4
     * @return list<class-string>
     */
    private function controllerClasses(array $psr4): array
    {
        $classes = [];
        foreach ($psr4 as $prefix => $dir) {
            $prefix = rtrim($prefix, '\\').'\\';
            if (! is_dir($dir)) {
                continue;
            }
            $realDir = rtrim((string) realpath($dir), DIRECTORY_SEPARATOR);
            /** @var iterable<\SplFileInfo> $files */
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($realDir, \RecursiveDirectoryIterator::SKIP_DOTS));
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
                if ($reflection->getAttributes(RestController::class, ReflectionAttribute::IS_INSTANCEOF) !== []) {
                    /** @var class-string $class */
                    $classes[] = $class;
                }
            }
        }
        sort($classes);

        return $classes;
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     */
    private function basePath(ReflectionClass $reflection): string
    {
        $attributes = $reflection->getAttributes(RequestMapping::class);

        return $attributes === [] ? '' : $attributes[0]->newInstance()->path;
    }

    private function join(string $base, string $path): string
    {
        $joined = rtrim($base, '/').'/'.ltrim($path, '/');
        $joined = '/'.trim($joined, '/');

        return $joined === '/' ? '/' : rtrim($joined, '/');
    }

    /**
     * @return list<Binding>
     */
    private function bindings(ReflectionMethod $method): array
    {
        $bindings = [];
        foreach ($method->getParameters() as $parameter) {
            $bindings[] = $this->binding($parameter);
        }

        return $bindings;
    }

    /**
     * @return Binding
     */
    private function binding(ReflectionParameter $parameter): array
    {
        $name = $parameter->getName();
        $type = $this->typeName($parameter);
        $valid = $parameter->getAttributes(Valid::class) !== [];
        $default = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;

        if (($attrs = $parameter->getAttributes(PathVariable::class)) !== []) {
            $pathVariable = $attrs[0]->newInstance();

            return $this->plan($name, 'path', $pathVariable->name ?? $name, $type, true, null, $valid);
        }

        if (($attrs = $parameter->getAttributes(RequestBody::class)) !== []) {
            return $this->plan($name, 'body', '', $type, true, null, $valid, $this->constructorProperties($type));
        }

        if (($attrs = $parameter->getAttributes(RequestHeader::class)) !== []) {
            $header = $attrs[0]->newInstance();

            return $this->plan($name, 'header', $header->name ?? $name, $type, false, $header->default, $valid);
        }

        if (($attrs = $parameter->getAttributes(UploadedFile::class)) !== []) {
            $file = $attrs[0]->newInstance();

            return $this->plan($name, 'file', $file->name ?? $name, $type, false, null, $valid);
        }

        if (($attrs = $parameter->getAttributes(QueryParam::class)) !== []) {
            $query = $attrs[0]->newInstance();

            return $this->plan($name, 'query', $query->name ?? $name, $type, $query->required, $query->default, $valid);
        }

        // No binding attribute: a class type is a container service; a scalar defaults to a query param.
        if ($type !== null && class_exists($type)) {
            return $this->plan($name, 'service', $type, $type, ! $parameter->isOptional(), $default, $valid);
        }

        return $this->plan($name, 'query', $name, $type, ! $parameter->isOptional(), $default, $valid);
    }

    /**
     * @param  list<string>  $properties
     * @return Binding
     */
    private function plan(string $name, string $kind, string $key, ?string $type, bool $required, mixed $default, bool $valid, array $properties = []): array
    {
        return [
            'name' => $name,
            'kind' => $kind,
            'key' => $key,
            'type' => $type,
            'required' => $required,
            'default' => $default,
            'valid' => $valid,
            'properties' => $properties,
        ];
    }

    private function typeName(ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();

        return $type instanceof ReflectionNamedType ? $type->getName() : null;
    }

    /**
     * @return list<string> constructor-promoted parameter names of a body DTO (empty if $type is not a class)
     */
    private function constructorProperties(?string $type): array
    {
        if ($type === null || ! class_exists($type)) {
            return [];
        }

        $constructor = (new ReflectionClass($type))->getConstructor();
        $names = [];
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $names[] = $parameter->getName();
        }

        return $names;
    }
}

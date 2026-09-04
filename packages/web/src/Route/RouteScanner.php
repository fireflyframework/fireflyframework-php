<?php

declare(strict_types=1);

namespace Firefly\Web\Route;

use Firefly\Validation\Valid;
use Firefly\Web\Attributes\Controller;
use Firefly\Web\Attributes\ControllerAdvice;
use Firefly\Web\Attributes\ExceptionHandler;
use Firefly\Web\Attributes\Mapping;
use Firefly\Web\Attributes\PathVariable;
use Firefly\Web\Attributes\QueryParam;
use Firefly\Web\Attributes\RequestBody;
use Firefly\Web\Attributes\RequestHeader;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;
use Firefly\Web\Attributes\UploadedFile;
use Firefly\Web\Exception\ExceptionHandlerDescriptor;
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

            // #[Controller] is the HTML stereotype and extends #[RestController], so it is found by the same
            // IS_INSTANCEOF scan; recording which one matched is the only way anything downstream can tell a
            // web page from a JSON operation without reflecting again.
            $html = $reflection->getAttributes(Controller::class, ReflectionAttribute::IS_INSTANCEOF) !== [];

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
                        html: $html,
                    );
                }
            }
        }

        return $descriptors;
    }

    /**
     * @param  array<string,string>  $psr4
     * @return list<ExceptionHandlerDescriptor>
     */
    public function scanExceptionHandlers(array $psr4): array
    {
        $handlers = [];

        foreach ($this->handlerClasses($psr4) as $class => $global) {
            $reflection = new ReflectionClass($class);
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(ExceptionHandler::class) as $attribute) {
                    $handler = $attribute->newInstance();
                    $handlers[] = new ExceptionHandlerDescriptor(
                        exceptionClass: $handler->exceptionClass,
                        handlerClass: $class,
                        methodName: $method->getName(),
                        global: $global,
                    );
                }
            }
        }

        return $handlers;
    }

    /**
     * @param  array<string,string>  $psr4
     * @return array<class-string, bool> class => isGlobal (#[ControllerAdvice] => true; #[RestController] => false)
     */
    private function handlerClasses(array $psr4): array
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
                if ($reflection->getAttributes(ControllerAdvice::class, ReflectionAttribute::IS_INSTANCEOF) !== []) {
                    /** @var class-string $class */
                    $classes[$class] = true;
                } elseif ($reflection->getAttributes(RestController::class, ReflectionAttribute::IS_INSTANCEOF) !== []) {
                    /** @var class-string $class */
                    $classes[$class] = false;
                }
            }
        }

        return $classes;
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
            return $this->plan($name, 'body', '', $type, true, null, $valid, $this->constructorProperties($type), $this->dtoShapes($type));
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
     * @param  array<string, array<string, array{class: string|null, list: bool}>>  $dtos
     * @return Binding
     */
    private function plan(string $name, string $kind, string $key, ?string $type, bool $required, mixed $default, bool $valid, array $properties = [], array $dtos = []): array
    {
        $binding = [
            'name' => $name,
            'kind' => $kind,
            'key' => $key,
            'type' => $type,
            'required' => $required,
            'default' => $default,
            'valid' => $valid,
            'properties' => $properties,
        ];

        // Emitted only when there is something to say, so a plan for a flat DTO is byte-identical to the one
        // this scanner produced before nested hydration existed, and an already-compiled manifest without the
        // key keeps working (ArgumentResolver reads `dtos` with a `?? []` default).
        if ($dtos !== []) {
            $binding['dtos'] = $dtos;
        }

        return $binding;
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

    /**
     * The shape table ArgumentResolver hydrates a nested request body from: one row per class reachable from
     * the body DTO, each row mapping a constructor parameter to the class it is built from (null for a
     * builtin) and whether the payload holds a LIST of that class.
     *
     * Compiled here because this is the one sanctioned reflection site in the package — the resolver runs on
     * the per-request hot path and must stay reflection-free (ReflectionFreeWebTest guards it).
     *
     * Keyed by CLASS rather than nested inline, so depth is unbounded: a DTO that points at itself is one row,
     * and $seen stops the WALK from recursing forever without capping how deep a payload may nest.
     *
     * @param  array<string, true>  $seen
     * @return array<string, array<string, array{class: string|null, list: bool}>>
     */
    private function dtoShapes(?string $type, array &$seen = []): array
    {
        if ($type === null || ! class_exists($type) || isset($seen[$type])) {
            return [];
        }

        $reflection = new ReflectionClass($type);
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return [];
        }

        $seen[$type] = true;
        $docTypes = $this->docblockParamTypes($constructor->getDocComment() ?: '', $reflection);

        $shape = [];
        $shapes = [];
        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            $parameterType = $parameter->getType();
            $named = $parameterType instanceof ReflectionNamedType ? $parameterType->getName() : null;

            // A class-typed parameter is a nested DTO; an `array` carries no element type in PHP, so its
            // element class can only come from the docblock.
            $nested = $named !== null && class_exists($named) ? $named : null;
            $isList = false;

            if ($nested === null && $named === 'array' && isset($docTypes[$name])) {
                $nested = $docTypes[$name];
                $isList = true;
            }

            $shape[$name] = ['class' => $nested, 'list' => $isList];

            if ($nested !== null) {
                $shapes = [...$shapes, ...$this->dtoShapes($nested, $seen)];
            }
        }

        return [$type => $shape, ...$shapes];
    }

    /**
     * Element classes read out of a constructor docblock: `@param list<Line> $lines`, `@param Line[] $lines`
     * and `@param array<int, Line> $lines` all mean the same thing to the hydrator.
     *
     * A docblock name may be written short, so it is resolved the way PHP would resolve it: an explicitly
     * leading-slashed or already-qualified name as-is, then the declaring class's own namespace, then the
     * file's `use` imports. Anything that does not resolve to a real class is left out of the table entirely,
     * which lands the value on the resolver's documented "plan cannot say" path — a clean 400 rather than a
     * guess.
     *
     * @param  ReflectionClass<object>  $declaring
     * @return array<string, string> parameter name => element class
     */
    private function docblockParamTypes(string $docComment, ReflectionClass $declaring): array
    {
        if ($docComment === '') {
            return [];
        }

        // Two patterns rather than one alternation: `list<X>`/`array<int, X>`/`iterable<X>` and the
        // `X[]` spelling. Kept separate so each match has a fixed shape.
        $types = [];

        foreach ([
            '/@param\s+(?:list|array|iterable)<(?:[^,<>]+,\s*)?([^<>]+)>\s+\$(\w+)/',
            '/@param\s+([\w\\\\]+)\[\]\s+\$(\w+)/',
        ] as $pattern) {
            if (preg_match_all($pattern, $docComment, $matches, PREG_SET_ORDER) === false) {
                continue;
            }

            foreach ($matches as $match) {
                $resolved = $this->resolveClassName(trim($match[1]), $declaring);
                if ($resolved !== null) {
                    $types[$match[2]] = $resolved;
                }
            }
        }

        return $types;
    }

    /**
     * @param  ReflectionClass<object>  $declaring
     */
    private function resolveClassName(string $name, ReflectionClass $declaring): ?string
    {
        $name = ltrim($name, '\\');
        if (class_exists($name)) {
            return $name;
        }

        $namespace = $declaring->getNamespaceName();
        if ($namespace !== '' && class_exists($candidate = $namespace.'\\'.$name)) {
            return $candidate;
        }

        foreach ($this->imports($declaring) as $alias => $fqcn) {
            if ($alias === $name && class_exists($fqcn)) {
                return $fqcn;
            }
        }

        return null;
    }

    /**
     * The file's `use` imports, alias => FQCN. Read from the source because reflection does not expose them.
     *
     * @param  ReflectionClass<object>  $declaring
     * @return array<string, string>
     */
    private function imports(ReflectionClass $declaring): array
    {
        $file = $declaring->getFileName();
        if ($file === false || ! is_file($file)) {
            return [];
        }

        $source = (string) file_get_contents($file);
        if (preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/mi', $source, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        $imports = [];
        foreach ($matches as $match) {
            $fqcn = $match[1];
            $alias = $match[2] ?? '';
            if ($alias === '') {
                $parts = explode('\\', $fqcn);
                $alias = end($parts);
            }
            $imports[$alias] = $fqcn;
        }

        return $imports;
    }
}

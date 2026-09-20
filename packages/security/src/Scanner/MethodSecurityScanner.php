<?php

declare(strict_types=1);

namespace Firefly\Security\Scanner;

use Firefly\Container\Attributes\Component;
use Firefly\Cqrs\Attributes\CommandHandler;
use Firefly\Cqrs\Attributes\QueryHandler;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\Access\Attributes\PostAuthorize;
use Firefly\Security\Access\Attributes\PostFilter;
use Firefly\Security\Access\Attributes\PreAuthorize;
use Firefly\Security\Access\Attributes\PreFilter;
use Firefly\Security\Access\Attributes\RolesAllowed;
use Firefly\Security\Access\Attributes\Secured;
use Firefly\Security\Access\Expression\ExpressionParseException;
use Firefly\Security\Access\Expression\SecurityExpressionEvaluator;
use Firefly\Security\Access\Method\SecurityMethodDescriptor;
use Firefly\Web\Attributes\RestController;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * The ONE scan-time reflection file in packages/security/src (grep invariant, enforced by ReflectionFreeSecurityTest).
 * Per concrete class it reads the effective #[PreAuthorize]/#[Secured]/#[RolesAllowed] on each public method — a
 * method-level attribute REPLACES a class-level one for that method (Spring semantics) — normalises #[Secured]/
 * #[RolesAllowed] to a hasAnyAuthority()/hasAnyRole() expression, and records the ordered parameter names so #param
 * references resolve at enforcement. Beside the pre rule it reads #[PostAuthorize] (class or method, same
 * replacement rule), #[PreFilter] and #[PostFilter] (method only), and a method carrying only those compiles a
 * `permitAll()` pre expression so every consumer of the pre rule keeps working. Every produced expression (raw
 * #[PreAuthorize] AND the generated hasAnyAuthority()/hasAnyRole() strings, the post and filter expressions) is fed
 * through SecurityExpressionEvaluator::parse() before it is accepted: a malformed or whitelist-violating expression
 * would otherwise compile silently and then DENY every call to that method forever (a permanent production 403
 * with no build-time signal), so scan() fails loud with a ConfigurationException naming the offending Class::method
 * instead. Runs only at cache time; production loads the compiled manifest via require+map. Mirrors
 * data/TransactionalScanner.
 *
 * @phpstan-import-type SecurityMethodRow from SecurityMethodDescriptor
 */
final class MethodSecurityScanner
{
    /**
     * @param  array<string,string>  $psr4  namespace-prefix => absolute directory
     * @return list<SecurityMethodDescriptor>
     */
    public function scan(array $psr4): array
    {
        $rules = [];
        $evaluator = new SecurityExpressionEvaluator;

        foreach ($this->classes($psr4) as $class) {
            $reflection = new ReflectionClass($class);

            try {
                $classPre = $this->preRuleFrom($reflection->getAttributes());
                $classPost = $this->postRuleFrom($reflection->getAttributes());
            } catch (ExpressionParseException $e) {
                throw new ConfigurationException("Invalid method-security expression on {$class}: {$e->getMessage()}", previous: $e);
            }

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || $method->isConstructor() || str_starts_with($method->getName(), '__')) {
                    continue;
                }

                $site = "{$class}::{$method->getName()}";

                try {
                    // A method-level attribute REPLACES a class-level one of the same kind (Spring semantics);
                    // the filters are method-only.
                    $pre = $this->preRuleFrom($method->getAttributes()) ?? $classPre;
                    $post = $this->postRuleFrom($method->getAttributes()) ?? $classPost;
                    $preFilter = $this->first($method->getAttributes(PreFilter::class));
                    $postFilter = $this->first($method->getAttributes(PostFilter::class));

                    if ($pre === null && $post === null && $preFilter === null && $postFilter === null) {
                        continue;
                    }

                    foreach ([$pre['expression'] ?? null, $post['expression'] ?? null, $preFilter?->expression, $postFilter?->expression] as $expression) {
                        if ($expression !== null) {
                            $evaluator->parse($expression);
                        }
                    }
                } catch (ExpressionParseException $e) {
                    throw new ConfigurationException("Invalid method-security expression on {$site}: {$e->getMessage()}", previous: $e);
                }

                $params = array_map(static fn (ReflectionParameter $p): string => $p->getName(), $method->getParameters());
                $target = $preFilter === null ? null : $this->filterTarget($method, $preFilter, $site);

                $rules[] = new SecurityMethodDescriptor(
                    $class,
                    $method->getName(),
                    $pre['expression'] ?? 'permitAll()',
                    $params,
                    $pre['code'] ?? null,
                    $pre['message'] ?? null,
                    $post['expression'] ?? null,
                    $post['code'] ?? null,
                    $post['message'] ?? null,
                    $preFilter?->expression,
                    $target,
                    $postFilter?->expression,
                );
            }
        }

        return $rules;
    }

    /**
     * The rows the proxy plan enforces: every rule on a class that has a #[Component]-family stereotype (a
     * plain class is never post-processed, so it cannot be proxied — its rules stay reachable through
     * AuthorizationChecker), EXCEPT a #[RestController]/#[Controller] (the dispatcher enforces pre and post
     * rules) and a #[CommandHandler]/#[QueryHandler] whose rules are all pre-invocation (the bus enforces
     * them). A class this leaves needing a proxy while being `final` is refused: the proxy must extend it,
     * and before this scan existed such a rule was silently unenforced.
     *
     * @param  array<string,string>  $psr4
     * @return array<class-string, array<string, SecurityMethodRow>>
     */
    public function scanProxyAdvice(array $psr4): array
    {
        /** @var array<class-string, list<SecurityMethodDescriptor>> $byClass */
        $byClass = [];
        foreach ($this->scan($psr4) as $rule) {
            /** @var class-string $class */
            $class = $rule->class;
            $byClass[$class][] = $rule;
        }

        $advice = [];
        foreach ($byClass as $class => $rules) {
            $reflection = new ReflectionClass($class);

            if ($reflection->getAttributes(Component::class, ReflectionAttribute::IS_INSTANCEOF) === []) {
                continue;
            }
            if ($reflection->getAttributes(RestController::class, ReflectionAttribute::IS_INSTANCEOF) !== []) {
                continue;
            }

            $handler = $reflection->getAttributes(CommandHandler::class) !== [] || $reflection->getAttributes(QueryHandler::class) !== [];
            $needsProxy = false;
            foreach ($rules as $rule) {
                if (! $handler || $rule->postExpression !== null || $rule->preFilter !== null || $rule->postFilter !== null) {
                    $needsProxy = true;
                }
            }
            if (! $needsProxy) {
                continue;
            }

            if ($reflection->isFinal()) {
                throw new ConfigurationException(
                    "Method security on {$class} cannot be enforced: the class is final and a proxy must extend it. "
                    .'Remove `final`, or move the rule onto the controller or handler that calls it.'
                );
            }

            foreach ($rules as $rule) {
                $advice[$class][$rule->method] = $rule->toArray();
            }
            ksort($advice[$class]);
        }

        return $advice;
    }

    /**
     * The PRE-invocation expression an attribute list declares, with the product code and sentence a
     * #[PreAuthorize] may carry beside it. #[Secured]/#[RolesAllowed] carry neither: they are the JSR-250
     * shorthands and have no slot for words, so a refusal on them reads the framework's sentence with the
     * authorities named.
     *
     * @param  list<ReflectionAttribute<object>>  $attributes
     * @return array{expression: string, code: string|null, message: string|null}|null
     */
    private function preRuleFrom(array $attributes): ?array
    {
        foreach ($attributes as $attribute) {
            $name = $attribute->getName();
            if ($name === PreAuthorize::class) {
                $instance = $attribute->newInstance();

                return $instance instanceof PreAuthorize
                    ? ['expression' => $instance->expression, 'code' => $instance->code, 'message' => $instance->message]
                    : null;
            }
            if ($name === Secured::class) {
                $instance = $attribute->newInstance();

                return $instance instanceof Secured
                    ? ['expression' => 'hasAnyAuthority('.$this->quoteList($instance->authorities).')', 'code' => null, 'message' => null]
                    : null;
            }
            if ($name === RolesAllowed::class) {
                $instance = $attribute->newInstance();

                return $instance instanceof RolesAllowed
                    ? ['expression' => 'hasAnyRole('.$this->quoteList($instance->roles).')', 'code' => null, 'message' => null]
                    : null;
            }
        }

        return null;
    }

    /**
     * @param  list<ReflectionAttribute<object>>  $attributes
     * @return array{expression: string, code: string|null, message: string|null}|null
     */
    private function postRuleFrom(array $attributes): ?array
    {
        foreach ($attributes as $attribute) {
            if ($attribute->getName() === PostAuthorize::class) {
                $instance = $attribute->newInstance();

                return $instance instanceof PostAuthorize
                    ? ['expression' => $instance->expression, 'code' => $instance->code, 'message' => $instance->message]
                    : null;
            }
        }

        return null;
    }

    /**
     * @template T of object
     *
     * @param  list<ReflectionAttribute<T>>  $attributes
     * @return T|null
     */
    private function first(array $attributes): ?object
    {
        return $attributes === [] ? null : $attributes[0]->newInstance();
    }

    /**
     * The parameter a #[PreFilter] narrows: the one it names, else the SOLE parameter typed array/iterable.
     * Anything else is refused at scan time — a filter that silently targeted the wrong argument would let
     * the unfiltered one through, which is a fail-open.
     */
    private function filterTarget(ReflectionMethod $method, PreFilter $filter, string $site): string
    {
        $names = array_map(static fn (ReflectionParameter $p): string => $p->getName(), $method->getParameters());

        if ($filter->filterTarget !== null) {
            if (! in_array($filter->filterTarget, $names, true)) {
                throw new ConfigurationException("#[PreFilter] on {$site} names filterTarget `{$filter->filterTarget}`, which is not a parameter of that method.");
            }

            return $filter->filterTarget;
        }

        $iterable = [];
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && in_array($type->getName(), ['array', 'iterable'], true)) {
                $iterable[] = $parameter->getName();
            }
        }

        if (count($iterable) !== 1) {
            throw new ConfigurationException("#[PreFilter] on {$site} cannot infer which parameter to filter: name it with filterTarget.");
        }

        return $iterable[0];
    }

    /**
     * Every #[Secured]/#[RolesAllowed] value is interpolated into a single-quoted expression literal
     * (hasAnyAuthority()/hasAnyRole()). A value containing a quote must be rejected outright rather than left to
     * the parser: splicing a quote in can produce a MALFORMED expression (caught by parse() below, e.g. an
     * apostrophe that breaks tokenization) OR a grammar-VALID one that silently widens access — e.g. the value
     * `X') or permitAll() or hasAnyRole('Y` compiles to `hasAnyRole('X') or permitAll() or hasAnyRole('Y')`, which
     * parses and evaluates successfully (always true). A legitimate role/authority never contains a quote
     * (Spring-style ROLE_X / resource:action strings), so this is a pure attribute-authoring constraint, not a
     * runtime limitation. Mirrors HttpSecurity::assertSafeValue() for the URL-rule DSL/config path.
     *
     * @param  list<string>  $values
     */
    private function quoteList(array $values): string
    {
        return implode(', ', array_map(static function (string $v): string {
            if (str_contains($v, "'")) {
                throw new ExpressionParseException("Illegal character in security authority/role value: {$v}");
            }

            return "'{$v}'";
        }, $values));
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

<?php

declare(strict_types=1);

namespace Firefly\Security\Scanner;

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\Access\Attributes\PreAuthorize;
use Firefly\Security\Access\Attributes\RolesAllowed;
use Firefly\Security\Access\Attributes\Secured;
use Firefly\Security\Access\Expression\ExpressionParseException;
use Firefly\Security\Access\Expression\SecurityExpressionEvaluator;
use Firefly\Security\Access\Method\SecurityMethodDescriptor;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;

/**
 * The ONE scan-time reflection file in packages/security/src (grep invariant, enforced by ReflectionFreeSecurityTest).
 * Per concrete class it reads the effective #[PreAuthorize]/#[Secured]/#[RolesAllowed] on each public method — a
 * method-level attribute REPLACES a class-level one for that method (Spring semantics) — normalises #[Secured]/
 * #[RolesAllowed] to a hasAnyAuthority()/hasAnyRole() expression, and records the ordered parameter names so #param
 * references resolve at enforcement. Every produced expression (raw #[PreAuthorize] AND the generated
 * hasAnyAuthority()/hasAnyRole() strings) is fed through SecurityExpressionEvaluator::parse() before it is
 * accepted: a malformed or whitelist-violating expression would otherwise compile silently and then DENY every
 * call to that method forever (a permanent production 403 with no build-time signal), so scan() fails loud with a
 * ConfigurationException naming the offending Class::method instead. Runs only at cache time; production loads
 * the compiled manifest via require+map. Mirrors data/TransactionalScanner.
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
                $classExpression = $this->expressionFrom($reflection->getAttributes());
            } catch (ExpressionParseException $e) {
                throw new ConfigurationException("Invalid method-security expression on {$class}: {$e->getMessage()}", previous: $e);
            }

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || $method->isConstructor() || str_starts_with($method->getName(), '__')) {
                    continue;
                }

                try {
                    $expression = $this->expressionFrom($method->getAttributes()) ?? $classExpression;
                    if ($expression === null) {
                        continue;
                    }
                    $evaluator->parse($expression);
                } catch (ExpressionParseException $e) {
                    throw new ConfigurationException(
                        "Invalid method-security expression on {$class}::{$method->getName()}: {$e->getMessage()}",
                        previous: $e,
                    );
                }

                $params = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $method->getParameters());
                $rules[] = new SecurityMethodDescriptor($class, $method->getName(), $expression, $params);
            }
        }

        return $rules;
    }

    /**
     * @param  list<\ReflectionAttribute<object>>  $attributes
     */
    private function expressionFrom(array $attributes): ?string
    {
        foreach ($attributes as $attribute) {
            $name = $attribute->getName();
            if ($name === PreAuthorize::class) {
                $instance = $attribute->newInstance();

                return $instance instanceof PreAuthorize ? $instance->expression : null;
            }
            if ($name === Secured::class) {
                $instance = $attribute->newInstance();

                return $instance instanceof Secured ? 'hasAnyAuthority('.$this->quoteList($instance->authorities).')' : null;
            }
            if ($name === RolesAllowed::class) {
                $instance = $attribute->newInstance();

                return $instance instanceof RolesAllowed ? 'hasAnyRole('.$this->quoteList($instance->roles).')' : null;
            }
        }

        return null;
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

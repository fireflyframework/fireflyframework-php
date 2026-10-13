<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Scanner;

use Firefly\Cqrs\Attributes\CachedQuery;
use Firefly\Cqrs\Attributes\CommandHandler;
use Firefly\Cqrs\Attributes\InvalidatesQuery;
use Firefly\Cqrs\Attributes\PublishDomainEvent;
use Firefly\Cqrs\Attributes\QueryHandler;
use Firefly\Cqrs\Cache\Cacheable;
use Firefly\Cqrs\Cache\CachedQueryPolicy;
use Firefly\Cqrs\Cache\InvalidationRule;
use Firefly\Cqrs\Exception\CqrsConfigurationException;
use Firefly\Cqrs\Handler\HandlerDescriptor;
use Firefly\Cqrs\Handler\HandlerKind;
use Firefly\Domain\DomainEvent;
use ReflectionClass;
use ReflectionNamedType;

/**
 * The ONE reflection file in packages/cqrs/src (grep invariant). Iterates the app PSR-4 roots and, per concrete
 * class: reads a class-level #[CommandHandler]/#[QueryHandler] (resolving the handled message type — explicit ctor
 * arg else inferred from the sole handle() parameter, mirroring #[AsEventListener]'s param inference); and collects
 * #[PublishDomainEvent] off DomainEvent subclasses into a {eventClass => destination} map. Fails loud
 * (CqrsConfigurationException) on an unresolvable type or a missing/multi-param handle() — never silently skips.
 * It also compiles #[CachedQuery] off query classes into per-query policies and #[InvalidatesQuery] off event classes
 * into invalidation rules, refusing a rule with both a key template and a `with` map, a rule naming a class that does
 * not exist, a `with` map that does not name exactly the query's public properties (or targets a Cacheable query or
 * one with non-public state), and a TTL below one second.
 * Runs only at cache time; production loads the compiled HandlerManifest via require+map. Mirrors ScheduledScanner.
 */
final class HandlerScanner
{
    /**
     * @param  array<string,string>  $psr4  namespace-prefix => absolute directory
     * @return array{handlers: list<HandlerDescriptor>, destinations: array<string,string>, cachedQueries: array<string, CachedQueryPolicy>, invalidations: list<InvalidationRule>}
     */
    public function scan(array $psr4): array
    {
        $handlers = [];
        $destinations = [];
        $cachedQueries = [];
        $invalidations = [];

        foreach ($this->classes($psr4) as $class) {
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getAttributes(CommandHandler::class) as $attribute) {
                $handlers[] = new HandlerDescriptor(
                    messageClass: $this->messageType($reflection, $attribute->newInstance()->command, $class),
                    handlerClass: $class,
                    method: 'handle',
                    kind: HandlerKind::Command,
                );
            }

            foreach ($reflection->getAttributes(QueryHandler::class) as $attribute) {
                $handlers[] = new HandlerDescriptor(
                    messageClass: $this->messageType($reflection, $attribute->newInstance()->query, $class),
                    handlerClass: $class,
                    method: 'handle',
                    kind: HandlerKind::Query,
                );
            }

            foreach ($reflection->getAttributes(PublishDomainEvent::class) as $attribute) {
                if (! is_subclass_of($class, DomainEvent::class)) {
                    continue;
                }
                $destination = $attribute->newInstance()->destination;
                if ($destination !== null) {
                    $destinations[$class] = $destination;
                }
            }

            foreach ($reflection->getAttributes(CachedQuery::class) as $attribute) {
                $cached = $attribute->newInstance();
                if ($cached->ttl !== null && $cached->ttl < 1) {
                    throw new CqrsConfigurationException("#[CachedQuery] on [{$class}] declares ttl {$cached->ttl}; a TTL is at least one second (omit it to use firefly.cqrs.query.cache_ttl).");
                }
                $cachedQueries[$class] = new CachedQueryPolicy($class, $cached->ttl, $cached->group ?? $class);
            }

            foreach ($reflection->getAttributes(InvalidatesQuery::class) as $attribute) {
                $rule = $attribute->newInstance();
                if (! class_exists($rule->query)) {
                    throw new CqrsConfigurationException("#[InvalidatesQuery] on [{$class}] names [{$rule->query}], which is not a class.");
                }
                if ($rule->key !== null && $rule->with !== []) {
                    throw new CqrsConfigurationException("#[InvalidatesQuery({$rule->query}::class)] on [{$class}] declares both key and with; a rule evicts by one or the other.");
                }
                if ($rule->with !== []) {
                    $this->assertWithMapsQuery($class, $rule->query, $rule->with);
                }
                $invalidations[] = new InvalidationRule($class, $rule->eventType ?? $reflection->getShortName(), $rule->query, $rule->key, $rule->with);
            }
        }

        return ['handlers' => $handlers, 'destinations' => $destinations, 'cachedQueries' => $cachedQueries, 'invalidations' => $invalidations];
    }

    /**
     * A `with` map names the query properties QueryCacheKey derives the key from, so it must name exactly the query's
     * public instance properties: an unknown name or an unmapped property derives a key no cached entry has, and the
     * eviction silently misses. A query holding private or protected state is never keyed by value (QueryCacheKey
     * answers null), and a Cacheable query is keyed by its own cacheKey(), so `with` can match neither.
     *
     * @param  class-string  $queryClass
     * @param  array<string, string>  $with
     */
    private function assertWithMapsQuery(string $eventClass, string $queryClass, array $with): void
    {
        $where = "#[InvalidatesQuery({$queryClass}::class)] on [{$eventClass}]";
        if (is_subclass_of($queryClass, Cacheable::class)) {
            throw new CqrsConfigurationException("{$where} declares a with map, but the query is Cacheable and keyed by its own cacheKey(); use a key template instead.");
        }

        $public = [];
        foreach ((new ReflectionClass($queryClass))->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }
            if (! $property->isPublic()) {
                throw new CqrsConfigurationException("{$where} declares a with map, but [{$queryClass}::\${$property->getName()}] is not public; a query keyed by value holds only public state.");
            }
            $public[] = $property->getName();
        }

        $unknown = array_diff(array_keys($with), $public);
        if ($unknown !== []) {
            throw new CqrsConfigurationException("{$where} maps [".implode(', ', $unknown)."], which [{$queryClass}] does not declare as public properties.");
        }

        $unmapped = array_diff($public, array_keys($with));
        if ($unmapped !== []) {
            throw new CqrsConfigurationException("{$where} leaves [".implode(', ', $unmapped).'] unmapped; a with map names every public property of the query.');
        }
    }

    /**
     * @param  ReflectionClass<object>  $reflection
     */
    private function messageType(ReflectionClass $reflection, ?string $explicit, string $handlerClass): string
    {
        if ($explicit !== null) {
            return $explicit;
        }

        if (! $reflection->hasMethod('handle')) {
            throw new CqrsConfigurationException("Handler [{$handlerClass}] declares no public handle() method. Add handle(<Message>) or an explicit #[CommandHandler(Message::class)].");
        }

        $parameters = $reflection->getMethod('handle')->getParameters();
        if (count($parameters) !== 1) {
            throw new CqrsConfigurationException("Handler [{$handlerClass}]::handle() must take exactly one message parameter, got ".count($parameters).'.');
        }

        $type = $parameters[0]->getType();
        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            throw new CqrsConfigurationException("Cannot infer the message type for [{$handlerClass}]::handle() from its parameter. Annotate #[CommandHandler(Message::class)] / #[QueryHandler(Message::class)] explicitly.");
        }

        return $type->getName();
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
                /** @var class-string $class */
                $classes[] = $class;
            }
        }
        sort($classes);

        return $classes;
    }
}

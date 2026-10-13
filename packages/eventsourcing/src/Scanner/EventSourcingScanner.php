<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Scanner;

use Firefly\Domain\DomainEvent;
use Firefly\EventSourcing\Event\Attributes\EventType;
use Firefly\EventSourcing\Event\EventMapper;
use Firefly\EventSourcing\Event\EventParameter;
use Firefly\EventSourcing\Event\EventTypeDescriptor;
use Firefly\EventSourcing\Event\EventTypeRegistry;
use Firefly\EventSourcing\Event\SerializableEvent;
use Firefly\EventSourcing\Manifest\EventSourcingManifest;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use SplFileInfo;

/**
 * The ONE reflection file in packages/eventsourcing/src. It walks the application's PSR-4 roots and compiles every
 * #[EventType] class: its type, version and whether it maps itself. For a convention-mapped class it also compiles
 * each constructor parameter's name, declared type, nullability and optionality, which EventMapper needs to build it
 * back without reflection. A class that is not a DomainEvent, and a type named twice, refuse the scan.
 *
 * The convention mapper WRITES an event's public properties and READS it back through its constructor, so a
 * convention-mapped class must have the two match by name (eventId and occurredAt aside, which travel in the
 * envelope). A constructor parameter with no public property of its name would be missing from every stored payload,
 * and a public property no constructor parameter takes would be stored and then silently dropped on reload. A
 * constructor parameter whose type EventMapper cannot rebuild from JSON (EventMapper::canBuild(), for example a
 * JsonSerializable value object, which is written but never read back) is refused too, and so is a union with a
 * member other than a JSON scalar or array, or an intersection (passesThrough()). Each refuses the scan here,
 * rather than surfacing as an unreadable stream at replay time.
 */
final class EventSourcingScanner
{
    /**
     * @param  array<string, string>  $psr4  namespace-prefix => absolute directory
     */
    public function scan(array $psr4): EventSourcingManifest
    {
        $descriptors = [];

        foreach ($this->classes($psr4) as $class) {
            $reflection = new ReflectionClass($class);
            $attributes = $reflection->getAttributes(EventType::class);
            if ($attributes === []) {
                continue;
            }
            if (! $reflection->isSubclassOf(DomainEvent::class)) {
                throw new ConfigurationException("#[EventType] on {$class}: an event type must extend Firefly\\Domain\\DomainEvent.");
            }

            $type = $attributes[0]->newInstance();
            $serializable = $reflection->implementsInterface(SerializableEvent::class);
            $parameters = [];
            if (! $serializable) {
                $this->assertRoundTrips($reflection);
                $composite = [];
                foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
                    $declared = $parameter->getType();
                    if (! $declared instanceof ReflectionNamedType && $declared !== null && ! self::passesThrough($declared)) {
                        $composite[] = '$'.$parameter->getName().' ('.$declared.')';
                    }
                    $name = $declared instanceof ReflectionNamedType ? $declared->getName() : null;
                    $parameters[] = new EventParameter($parameter->getName(), $name === 'self' || $name === 'static' ? $class : $name, $declared === null || $declared->allowsNull(), $parameter->isOptional());
                }
                $this->assertBuildable($class, $parameters, $composite);
            }

            /** @var class-string<DomainEvent> $class */
            $descriptors[] = new EventTypeDescriptor($class, $type->name, $type->version, $serializable, $parameters);
        }

        usort($descriptors, static fn (EventTypeDescriptor $a, EventTypeDescriptor $b): int => $a->type <=> $b->type);
        new EventTypeRegistry($descriptors);

        return new EventSourcingManifest($descriptors);
    }

    /**
     * A convention-mapped event stores its public instance properties and is rebuilt from its constructor parameters,
     * so both sets must name the same fields, or what is appended cannot be read back as it was written.
     *
     * @param  ReflectionClass<object>  $reflection
     */
    private function assertRoundTrips(ReflectionClass $reflection): void
    {
        $envelope = ['eventId', 'occurredAt'];
        $parameters = array_values(array_diff(array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            $reflection->getConstructor()?->getParameters() ?? [],
        ), $envelope));
        $properties = array_values(array_diff(array_map(
            static fn (ReflectionProperty $property): string => $property->getName(),
            array_filter($reflection->getProperties(ReflectionProperty::IS_PUBLIC), static fn (ReflectionProperty $property): bool => ! $property->isStatic()),
        ), $envelope));

        $unstored = array_values(array_diff($parameters, $properties));
        $unread = array_values(array_diff($properties, $parameters));
        if ($unstored === [] && $unread === []) {
            return;
        }

        $problems = [];
        if ($unstored !== []) {
            $problems[] = 'the constructor parameter(s) $'.implode(', $', $unstored).' have no public property of the same name, so they would never be stored';
        }
        if ($unread !== []) {
            $problems[] = 'the public property(ies) $'.implode(', $', $unread).' are not constructor parameters, so they would be stored and lost on reload';
        }

        throw new ConfigurationException("#[EventType] on {$reflection->getName()}: ".implode('; ', $problems).'. The convention mapper stores public properties and rebuilds the event through its constructor, so the two must match by name; rename them, or implement Firefly\\EventSourcing\\Event\\SerializableEvent to map the payload yourself.');
    }

    /**
     * Every convention-mapped constructor parameter must have a type EventMapper can rebuild from its stored value.
     * $composite carries the union and intersection parameters already refused by passesThrough(): they compile as
     * type null, which canBuild() accepts, so they are named here rather than found again in $parameters.
     *
     * @param  list<EventParameter>  $parameters
     * @param  list<string>  $composite
     */
    private function assertBuildable(string $class, array $parameters, array $composite): void
    {
        $unbuildable = $composite;
        foreach ($parameters as $parameter) {
            if (! in_array($parameter->name, ['eventId', 'occurredAt'], true) && ! EventMapper::canBuild($parameter->type)) {
                $unbuildable[] = '$'.$parameter->name.' ('.$parameter->type.')';
            }
        }
        if ($unbuildable === []) {
            return;
        }

        throw new ConfigurationException("#[EventType] on {$class}: the constructor parameter(s) ".implode(', ', $unbuildable).' have a type the convention mapper cannot rebuild from the stored payload, so the event could be appended but never read back. It builds int, float, string, bool, array, mixed and untyped parameters, unions of those, dates and enums; implement Firefly\\EventSourcing\\Event\\SerializableEvent to map the other types yourself.');
    }

    /**
     * A union or intersection parameter compiles as type null, so EventMapper hands it the stored JSON value as is.
     * That is right only when every non-null member of a union is a JSON scalar, array or mixed: a class member (a
     * date, an enum, a value object) would be given a string or an array it cannot accept, or would silently come
     * back as that string. An intersection only ever names classes, so it never passes through.
     */
    private static function passesThrough(ReflectionType $declared): bool
    {
        if (! $declared instanceof ReflectionUnionType) {
            return false;
        }
        foreach ($declared->getTypes() as $member) {
            if (! $member instanceof ReflectionNamedType || ! in_array($member->getName(), ['null', 'int', 'float', 'string', 'bool', 'false', 'true', 'array', 'mixed'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string>  $psr4
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
            /** @var iterable<SplFileInfo> $files */
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
                if ((new ReflectionClass($class))->isAbstract()) {
                    continue;
                }
                $classes[] = $class;
            }
        }
        sort($classes);

        return $classes;
    }
}

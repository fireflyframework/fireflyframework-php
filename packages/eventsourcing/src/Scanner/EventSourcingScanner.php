<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Scanner;

use Firefly\Domain\DomainEvent;
use Firefly\EventSourcing\Event\Attributes\EventType;
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
use SplFileInfo;

/**
 * The ONE reflection file in packages/eventsourcing/src. It walks the application's PSR-4 roots and compiles every
 * #[EventType] class: its type, version and whether it maps itself. For a convention-mapped class it also compiles
 * each constructor parameter's name, declared type, nullability and optionality, which EventMapper needs to build it
 * back without reflection. A class that is not a DomainEvent, and a type named twice, refuse the scan.
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
                foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
                    $declared = $parameter->getType();
                    $name = $declared instanceof ReflectionNamedType ? $declared->getName() : null;
                    $parameters[] = new EventParameter($parameter->getName(), $name === 'self' || $name === 'static' ? $class : $name, $declared === null || $declared->allowsNull(), $parameter->isOptional());
                }
            }

            /** @var class-string<DomainEvent> $class */
            $descriptors[] = new EventTypeDescriptor($class, $type->name, $type->version, $serializable, $parameters);
        }

        usort($descriptors, static fn (EventTypeDescriptor $a, EventTypeDescriptor $b): int => $a->type <=> $b->type);
        new EventTypeRegistry($descriptors);

        return new EventSourcingManifest($descriptors);
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

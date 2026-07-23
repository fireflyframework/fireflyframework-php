<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Scanner;

use Firefly\Cqrs\Attributes\CommandHandler;
use Firefly\Cqrs\Attributes\PublishDomainEvent;
use Firefly\Cqrs\Attributes\QueryHandler;
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
 * Runs only at cache time; production loads the compiled HandlerManifest via require+map. Mirrors ScheduledScanner.
 */
final class HandlerScanner
{
    /**
     * @param  array<string,string>  $psr4  namespace-prefix => absolute directory
     * @return array{handlers: list<HandlerDescriptor>, destinations: array<string,string>}
     */
    public function scan(array $psr4): array
    {
        $handlers = [];
        $destinations = [];

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
        }

        return ['handlers' => $handlers, 'destinations' => $destinations];
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

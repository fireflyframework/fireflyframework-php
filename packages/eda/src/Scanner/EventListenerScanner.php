<?php

declare(strict_types=1);

namespace Firefly\Eda\Scanner;

use Firefly\Eda\Attributes\EventListener;
use Firefly\Eda\Listener\EventListenerDescriptor;
use ReflectionClass;
use ReflectionMethod;

/**
 * The ONE reflection file in packages/eda/src (grep invariant). Iterates the app PSR-4 roots, reflects every
 * public method carrying #[EventListener], and emits a pure-array EventListenerDescriptor per annotation. Runs
 * only at cache time; production loads the compiled EventListenerManifest via require+map. Mirrors ScheduledScanner.
 */
final class EventListenerScanner
{
    /**
     * @param  array<string,string>  $psr4  namespace-prefix => absolute directory
     * @return list<EventListenerDescriptor>
     */
    public function scan(array $psr4): array
    {
        $descriptors = [];

        foreach ($this->classes($psr4) as $class) {
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(EventListener::class) as $attribute) {
                    $listener = $attribute->newInstance();
                    $descriptors[] = new EventListenerDescriptor(
                        class: $class,
                        method: $method->getName(),
                        patterns: $listener->patterns,
                        order: $listener->order,
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

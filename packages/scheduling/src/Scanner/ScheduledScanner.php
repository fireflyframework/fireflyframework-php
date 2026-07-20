<?php

declare(strict_types=1);

namespace Firefly\Scheduling\Scanner;

use Firefly\Scheduling\Attributes\Scheduled;
use Firefly\Scheduling\Schedule\ScheduledDescriptor;
use ReflectionClass;
use ReflectionMethod;

/**
 * The ONE reflection file in packages/scheduling/src (grep invariant). Iterates the app PSR-4 roots, reflects
 * every public method carrying #[Scheduled], and emits a pure-array ScheduledDescriptor per annotation. Runs
 * only at cache time; production loads the compiled ScheduledManifest via require+map.
 */
final class ScheduledScanner
{
    /**
     * @param  array<string,string>  $psr4  namespace-prefix => absolute directory
     * @return list<ScheduledDescriptor>
     */
    public function scan(array $psr4): array
    {
        $descriptors = [];

        foreach ($this->classes($psr4) as $class) {
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(Scheduled::class) as $attribute) {
                    $scheduled = $attribute->newInstance();
                    $descriptors[] = new ScheduledDescriptor(
                        class: $class,
                        method: $method->getName(),
                        cron: $scheduled->cron,
                        fixedRate: $scheduled->fixedRate,
                        fixedDelay: $scheduled->fixedDelay,
                        initialDelay: $scheduled->initialDelay,
                        zone: $scheduled->zone,
                        lockName: $this->lockName($scheduled, $class, $method->getName()),
                        lockTtl: $scheduled->lockTtl,
                    );
                }
            }
        }

        return $descriptors;
    }

    private function lockName(Scheduled $scheduled, string $class, string $method): ?string
    {
        return match (true) {
            $scheduled->lock === true => $class.'::'.$method,
            is_string($scheduled->lock) => $scheduled->lock,
            default => null, // null or false => unlocked
        };
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

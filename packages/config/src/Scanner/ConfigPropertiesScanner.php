<?php

declare(strict_types=1);

namespace Firefly\Config\Scanner;

use Firefly\Config\Attributes\ConfigProperties;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Discovers #[ConfigProperties] DTOs under PSR-4 namespaces. Mirrors firefly/container's ComponentScanner
 * idiom: discovery uses class_exists() (autoloads), so each prefix => dir must also be registered with the
 * active Composer autoloader; a trailing "\\" on the prefix is optional (normalized internally).
 */
final class ConfigPropertiesScanner
{
    /**
     * @param  array<string,string>  $psr4  namespace-prefix => absolute directory
     * @return list<ConfigPropertiesDescriptor>
     */
    public function scan(array $psr4): array
    {
        $descriptors = [];
        foreach ($psr4 as $prefix => $dir) {
            foreach ($this->classesIn($prefix, $dir) as $class) {
                $attrs = (new ReflectionClass($class))->getAttributes(ConfigProperties::class);
                if ($attrs === []) {
                    continue;
                }
                $descriptors[] = new ConfigPropertiesDescriptor($class, $attrs[0]->newInstance()->prefix);
            }
        }

        return $descriptors;
    }

    /**
     * @return list<class-string>
     */
    private function classesIn(string $prefix, string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $prefix = rtrim($prefix, '\\').'\\';
        $realDir = rtrim((string) realpath($dir), DIRECTORY_SEPARATOR);
        $classes = [];

        /** @var iterable<\SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($realDir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr((string) $file->getRealPath(), strlen($realDir) + 1, -4);
            $class = $prefix.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
            if (class_exists($class)) {
                /** @var class-string $class */
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }
}

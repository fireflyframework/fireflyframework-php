<?php

declare(strict_types=1);

namespace Firefly\Messaging\Scanner;

use Firefly\Messaging\Attributes\MessageListener;
use Firefly\Messaging\Listener\MessageListenerDescriptor;
use ReflectionClass;
use ReflectionMethod;

/**
 * The ONE reflection file in packages/messaging/src (grep invariant). Iterates the app PSR-4 roots, reflects every
 * public method carrying #[MessageListener], and emits a pure-array MessageListenerDescriptor. Runs only at cache
 * time; production loads the compiled MessageListenerManifest via require+map. Mirrors EventListenerScanner.
 */
final class MessageListenerScanner
{
    /**
     * @param  array<string,string>  $psr4  namespace-prefix => absolute directory
     * @return list<MessageListenerDescriptor>
     */
    public function scan(array $psr4): array
    {
        $descriptors = [];

        foreach ($this->classes($psr4) as $class) {
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($method->getAttributes(MessageListener::class) as $attribute) {
                    $listener = $attribute->newInstance();
                    $descriptors[] = new MessageListenerDescriptor(
                        class: $class,
                        method: $method->getName(),
                        topic: $listener->topic,
                        group: $listener->group,
                        retries: $listener->retries,
                        retryDelay: $listener->retryDelay,
                        deadLetterTopic: $listener->deadLetterTopic,
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

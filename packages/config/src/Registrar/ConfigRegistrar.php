<?php

declare(strict_types=1);

namespace Firefly\Config\Registrar;

use Firefly\Config\Binder\ConfigBinder;
use Firefly\Config\Binder\ReflectionConfigBinder;
use Firefly\Config\Config;
use Firefly\Config\Scanner\ConfigPropertiesDescriptor;
use Firefly\Config\Scanner\ConfigPropertiesManifest;
use Firefly\Config\Value\ConfigValueResolver;
use Firefly\Container\Value\ValueResolver;
use Illuminate\Container\Container;

/**
 * Wires config into the container: installs the config-backed ValueResolver (overriding firefly/container's
 * DefaultValueResolver) and registers each #[ConfigProperties] DTO as a singleton bound from its config
 * subtree. Idempotent per container.
 */
final class ConfigRegistrar
{
    private const REGISTERED = 'firefly.config.registered';

    private ConfigBinder $binder;

    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
        ?ConfigBinder $binder = null,
    ) {
        $this->binder = $binder ?? new ReflectionConfigBinder;
    }

    public function register(ConfigPropertiesManifest $manifest): void
    {
        if ($this->container->bound(self::REGISTERED)) {
            return;
        }
        $this->container->instance(self::REGISTERED, true);

        // Config-backed resolution wins over firefly/container's DefaultValueResolver.
        $this->container->instance(ValueResolver::class, new ConfigValueResolver($this->config));

        foreach ($manifest->properties as $descriptor) {
            $this->registerProperties($descriptor);
        }
    }

    private function registerProperties(ConfigPropertiesDescriptor $descriptor): void
    {
        /** @var class-string $class */
        $class = $descriptor->class;
        $prefix = $descriptor->prefix;
        $config = $this->config;
        $binder = $this->binder;

        $this->container->singleton($class, static function () use ($binder, $class, $prefix, $config): object {
            /** @var array<string,mixed> $subtree */
            $subtree = $config->array($prefix, []);

            return $binder->bind($class, $subtree);
        });
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Config\Registrar;

use Firefly\Config\Binder\ConfigBinder;
use Firefly\Config\Binder\ReflectionConfigBinder;
use Firefly\Config\Config;
use Firefly\Config\Profile\ProfileResolver;
use Firefly\Config\Profile\Profiles;
use Firefly\Config\Scanner\ConfigPropertiesDescriptor;
use Firefly\Config\Scanner\ConfigPropertiesManifest;
use Firefly\Config\Value\ConfigValueResolver;
use Firefly\Container\Value\ValueResolver;
use Illuminate\Container\Container;

/**
 * Wires config into the container: installs the config-backed ValueResolver (overriding firefly/container's
 * DefaultValueResolver) and registers each #[ConfigProperties] DTO as a singleton bound from its config
 * subtree. Idempotent per container.
 *
 * PROFILE GATING. A DTO that declares #[Profile] is registered only when one of those profiles is
 * active. Until this landed, #[Profile] was inert across the entire framework — the attribute
 * existed, the documentation described it, and `grep -rn 'Profile::class' packages/<any>/src`
 * matched nothing, so a DTO marked #[Profile('prod')] was bound in dev, in test and in CI exactly
 * as if the annotation were a comment. Skipping the binding (rather than binding a null, or binding
 * a "disabled" instance) is the honest failure mode and the one Spring chose: an excluded bean does
 * not exist, so injecting it fails loudly at resolution time instead of quietly handing back
 * configuration that was meant to be unreachable.
 */
final class ConfigRegistrar
{
    private const REGISTERED = 'firefly.config.registered';

    private ConfigBinder $binder;

    private Profiles $profiles;

    /**
     * $profiles is optional because the framework's own call site — firefly/context's
     * FlushDefinitionsPass — constructs this registrar with two arguments, and a package below
     * Context in the layer graph cannot reach up to change it. Falling back to ProfileResolver
     * rather than to "no gating" is deliberate: an omitted argument must not silently disable the
     * gate, which would reintroduce the exact bug this class now fixes. A caller that already holds
     * a resolved Profiles (a BootPass has one on its BootContext) should still pass it, so the whole
     * boot agrees on one profile set instead of resolving it twice.
     */
    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
        ?ConfigBinder $binder = null,
        ?Profiles $profiles = null,
    ) {
        $this->binder = $binder ?? new ReflectionConfigBinder;
        $this->profiles = $profiles ?? (new ProfileResolver)->resolve();
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
            if (! $this->profiles->accepts($descriptor->profiles)) {
                continue;
            }

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

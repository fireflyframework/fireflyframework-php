<?php

declare(strict_types=1);

namespace Firefly\Container\Registrar;

use Closure;
use Firefly\Container\Attributes\Qualifier;
use Firefly\Container\Attributes\Value;
use Firefly\Container\Descriptor\BeanDescriptor;
use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Container\Scope;
use Firefly\Container\Value\DefaultValueResolver;
use Firefly\Container\Value\ValueResolver;
use Firefly\Kernel\Exception\Framework\BeanNotFoundException;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Container\Container;

final class ContainerRegistrar
{
    private const REGISTERED = 'firefly.container.registered';

    public function __construct(private readonly Container $container) {}

    public function register(ComponentManifest $manifest): void
    {
        // Illuminate's tag() APPENDS rather than replaces, so a second call to
        // register() on the same container would duplicate every tagged binding
        // (e.g. getAll() returning 6 instances instead of 3). Providers can be
        // register()/boot()-invoked more than once, so guard with a per-container
        // sentinel: a fresh container is never bound here, making register() a
        // no-op on repeat calls against an already-registered container.
        if ($this->container->bound(self::REGISTERED)) {
            return;
        }
        $this->container->instance(self::REGISTERED, true);

        $this->registerValueSupport();
        $this->registerQualifierSupport();

        foreach ($manifest->components as $component) {
            $this->bindClass($component);
            $this->registerName($component);
        }

        // Beans are registered AFTER every component, in one sweep over the whole
        // manifest rather than interleaved per component. Both changes are load-bearing:
        // registerBeans() has to see every #[Bean] of a given return type at once to
        // detect competing definitions across DIFFERENT #[Configuration] classes, and
        // running it last makes the precedence rule unambiguous — where a #[Bean] name
        // and a component name collide, the explicitly declared bean wins.
        $this->wireInterfaces($manifest, $this->registerBeans($manifest));
    }

    public function tagFor(string $interface): string
    {
        return 'firefly.contract.'.$interface;
    }

    private function bindClass(ComponentDescriptor $component): void
    {
        $class = $component->class;

        match ($component->scope) {
            Scope::Singleton => $this->container->singleton($class, $class),
            Scope::Transient => $this->container->bind($class, $class),
            Scope::Scoped => $this->container->scoped($class, $class),
        };
    }

    private function registerName(ComponentDescriptor $component): void
    {
        $name = $component->name ?? $component->qualifier;
        if ($name !== null && $name !== $component->class) {
            // Alias registration is last-registration-wins by design, mirroring
            // Illuminate's Container::alias(): if two components register the
            // same name/qualifier, the later one silently rebinds the alias.
            $this->container->alias($component->class, $name);
        }
    }

    /**
     * Register every #[Bean] factory in the manifest, grouped by the type it produces.
     *
     * WHAT WAS BROKEN. Each bean was bound under its RETURN TYPE and its name was only
     * ever recorded as `alias($bean->returns, $bean->name)`. An alias is a pointer to a
     * key, not a binding of its own, so two #[Bean] methods returning the same type
     * COLLAPSED: both names pointed at the single type key, that key held whichever
     * factory registered last, and `getByName('memoryCache')` and
     * `getByName('redisCache')` handed back the very same object. Nothing errored;
     * one of the two beans simply never existed. #[Primary] could not break the tie
     * either — BeanDescriptor::$primary was read NOWHERE in the bean path (only
     * ComponentDescriptor::$primary was, in wireInterfaces(), and only for components).
     *
     * THE RULE NOW, per return type:
     *
     *  - ONE bean produces the type (overwhelmingly the common case): unchanged.
     *    The factory is bound on the return type and the name, if any, is aliased to
     *    it — so the type and the name keep resolving to the SAME singleton.
     *  - SEVERAL beans produce the type: each is bound under its OWN name key, so every
     *    one is individually resolvable, and the type key becomes an ALIAS of the
     *    #[Primary] winner (an alias, never a second binding — a second binding of the
     *    same factory would quietly mint a second "singleton" of one bean).
     *    With no #[Primary] the type key is bound to a guard factory that throws a
     *    NoUniqueBeanDefinition-style ConfigurationException naming the candidates, which
     *    beats both silently picking one and Illuminate's opaque "Target [X] is not
     *    instantiable"; the type stays BOUND so #[ConditionalOnMissingBean] still sees
     *    that a bean of that type exists.
     *
     * Two shapes cannot be expressed at all and are rejected at REGISTRATION time,
     * where the stack trace still points at the manifest rather than at some unlucky
     * consumer: competing beans that are anonymous (no name => unreachable, and no way
     * to disambiguate), and competing beans sharing one name (one would silently
     * overwrite the other). More than one #[Primary] for a type is rejected there too.
     *
     * @return array<string, true> every type key CLAIMED by a #[Bean] factory, whether
     *                             or not it resolves — wireInterfaces() must not
     *                             overwrite a contested type with a scanned impl either
     */
    private function registerBeans(ComponentManifest $manifest): array
    {
        /** @var array<string, list<array{ComponentDescriptor, BeanDescriptor}>> $byType */
        $byType = [];
        foreach ($manifest->components as $component) {
            foreach ($component->beans as $bean) {
                // A builtin or untyped return records '' (see ComponentScanner): there
                // is no type key to bind, so the bean is not registrable at all.
                if ($bean->returns === '') {
                    continue;
                }
                $byType[$bean->returns][] = [$component, $bean];
            }
        }

        $claimed = [];
        foreach ($byType as $type => $candidates) {
            $claimed[$type] = true;

            if (count($candidates) === 1) {
                [$component, $bean] = $candidates[0];
                $this->bindBean($type, $component, $bean);

                if ($bean->name !== null && $bean->name !== $type) {
                    // Same last-registration-wins semantics as registerName() above.
                    $this->container->alias($type, $bean->name);
                }

                continue;
            }

            $this->bindCompetingBeans($type, $candidates);
        }

        return $claimed;
    }

    /**
     * Register the two-or-more-beans-per-type case validated and named.
     *
     * @param  list<array{ComponentDescriptor, BeanDescriptor}>  $candidates
     */
    private function bindCompetingBeans(string $type, array $candidates): void
    {
        /** @var array<string, array{ComponentDescriptor, BeanDescriptor}> $byName */
        $byName = [];
        $anonymous = [];

        foreach ($candidates as [$component, $bean]) {
            $origin = $component->class.'::'.$bean->method.'()';

            if ($bean->name === null) {
                $anonymous[] = $origin;

                continue;
            }

            if ($bean->name === $type) {
                // The contested type key is owned by the GROUP — it is either an alias
                // of the #[Primary] winner or the ambiguity guard below. A candidate
                // that names itself after that key would have its own binding silently
                // replaced by whichever of the two lands last, leaving a declared bean
                // with no reachable name at all.
                throw new ConfigurationException(sprintf(
                    '%s is named after the very type it competes for (%s). That name IS the type key, '
                    .'which the #[Primary] winner claims for the whole group; give the bean a name of '
                    .'its own so it stays individually resolvable.',
                    $origin,
                    $type,
                ));
            }

            if (isset($byName[$bean->name])) {
                [$owner, $ownerBean] = $byName[$bean->name];

                throw new ConfigurationException(sprintf(
                    "Duplicate #[Bean] name '%s' for type %s: %s and %s both register under it. "
                    .'A bean name is a container key, so the second would silently overwrite the first; '
                    .'give each competing #[Bean] method a distinct name.',
                    $bean->name,
                    $type,
                    $owner->class.'::'.$ownerBean->method.'()',
                    $origin,
                ));
            }

            $byName[$bean->name] = [$component, $bean];
        }

        if ($anonymous !== []) {
            throw new ConfigurationException(sprintf(
                'No unique bean of type %s: %d #[Bean] methods produce it and %d of them '
                .'declare no name (%s). An anonymous bean is only reachable through its return type, '
                .'which its competitors already claim, so it can never be resolved. Give every competing '
                ."#[Bean] method an explicit name — #[Bean('someName')] — and mark exactly one #[Primary] "
                .'to become the default for the bare type.',
                $type,
                count($candidates),
                count($anonymous),
                implode(', ', $anonymous),
            ));
        }

        $primaries = [];
        foreach ($byName as $name => [, $bean]) {
            if ($bean->primary) {
                $primaries[] = $name;
            }
        }

        if (count($primaries) > 1) {
            throw new ConfigurationException(sprintf(
                'Type %s is produced by more than one #[Primary] #[Bean] (%s). #[Primary] exists to '
                .'name the single default for a contested type, so at most one candidate may carry it.',
                $type,
                implode(', ', $primaries),
            ));
        }

        foreach ($byName as $name => [$component, $bean]) {
            $this->bindBean($name, $component, $bean);
        }

        if ($primaries === []) {
            $this->bindAmbiguousType($type, array_keys($byName));

            return;
        }

        // An ALIAS, not a binding: the type must resolve to the very instance the
        // primary's own name resolves to, or a Scope::Singleton bean would exist twice.
        // No candidate can be named $type (rejected above), so this never self-aliases.
        $this->container->alias($primaries[0], $type);
    }

    /**
     * Bind a contested type with no #[Primary] to a factory that refuses, loudly.
     *
     * Leaving the type unbound instead would be worse in both directions: a concrete
     * return type would silently AUTO-WIRE (bypassing every #[Bean] factory and handing
     * back an object the configuration never produced), while an interface would fail
     * with Illuminate's "Target [X] is not instantiable" — technically true, entirely
     * unhelpful, and not a hint that two beans are competing.
     *
     * @param  list<string>  $names  the competing bean names, all individually resolvable
     */
    private function bindAmbiguousType(string $type, array $names): void
    {
        $this->container->bind($type, static function () use ($type, $names): never {
            throw new ConfigurationException(sprintf(
                'No unique bean of type %s: %d candidates (%s). Mark exactly one #[Bean] method '
                .'#[Primary] to make it the default for this type, or ask for the one you want by name — '
                ."#[Qualifier('%s')] on the injected parameter, or getByName('%s').",
                $type,
                count($names),
                implode(', ', $names),
                $names[0],
                $names[0],
            ));
        });
    }

    private function bindBean(string $key, ComponentDescriptor $component, BeanDescriptor $bean): void
    {
        $factory = $this->beanFactory($component->class, $bean->method);

        match ($bean->scope) {
            Scope::Singleton => $this->container->singleton($key, $factory),
            Scope::Transient => $this->container->bind($key, $factory),
            Scope::Scoped => $this->container->scoped($key, $factory),
        };
    }

    private function beanFactory(string $configClass, string $method): Closure
    {
        return function (Container $c) use ($configClass, $method): mixed {
            /** @var object $config */
            $config = $c->make($configClass);

            // The scanner only records #[Bean] on public methods that
            // exist on $configClass (see ComponentScanner::beansOf()),
            // so this array is guaranteed to be a valid callable; PHPStan
            // cannot verify that from a dynamic method-name string alone.
            /** @var callable $callable */
            $callable = [$config, $method];

            return $c->call($callable);
        };
    }

    private function registerValueSupport(): void
    {
        if (! $this->container->bound(ValueResolver::class)) {
            $this->container->singleton(ValueResolver::class, DefaultValueResolver::class);
        }

        // Resolve #[Value] parameters through the bound ValueResolver.
        $this->container->whenHasAttribute(
            Value::class,
            fn (Value $attribute): mixed => $this->container->make(ValueResolver::class)->resolve($attribute->expression),
        );
    }

    /**
     * Teach the container to honour #[Qualifier] on an injected parameter.
     *
     * This is the runtime half of the attribute, and until now it did not exist:
     * #[Qualifier] declared TARGET_PARAMETER and nothing anywhere read it, so
     * `#[Qualifier('redisCache')] Cache $cache` was injected from Cache::class like an
     * unannotated parameter — the wrong bean, silently, with the application none the
     * wiser. It rides the SAME Illuminate seam #[Value] already uses (see
     * registerValueSupport() and Qualifier's docblock for why that seam, and not the
     * compiled manifest, is the right carrier for a per-parameter instruction).
     *
     * The name is a container key, resolved exactly as getByName() would resolve it, so
     * it reaches the bean that registerBeans() bound under that name. An unknown name is
     * a BeanNotFoundException naming the qualifier: without the explicit bound() check
     * Illuminate would report `Target class [redisCache] does not exist`, which sends
     * the reader hunting for a class that was never meant to be one.
     */
    private function registerQualifierSupport(): void
    {
        $this->container->whenHasAttribute(
            Qualifier::class,
            function (Qualifier $attribute): mixed {
                if (! $this->container->bound($attribute->name)) {
                    throw new BeanNotFoundException(sprintf(
                        "No bean named '%s' is registered, so #[Qualifier('%s')] cannot be satisfied. "
                        .'Check the #[Bean] name or the #[Component]/#[Qualifier] name it refers to.',
                        $attribute->name,
                        $attribute->name,
                    ));
                }

                return $this->container->make($attribute->name);
            },
        );
    }

    /**
     * @param  array<string, true>  $beanBoundTypes  interface/type keys already claimed by a #[Bean] factory
     */
    private function wireInterfaces(ComponentManifest $manifest, array $beanBoundTypes): void
    {
        /** @var array<string, list<ComponentDescriptor>> $byInterface */
        $byInterface = [];
        foreach ($manifest->components as $component) {
            foreach ($component->interfaces as $interface) {
                $byInterface[$interface][] = $component;
            }
        }

        foreach ($byInterface as $interface => $impls) {
            // Tag all implementations for ordered list resolution. This must
            // happen unconditionally — getAll()/tagged() behavior is unaffected
            // by whether a #[Bean] also claims this interface.
            $this->container->tag(
                array_map(static fn (ComponentDescriptor $c): string => $c->class, $impls),
                $this->tagFor($interface),
            );

            // An explicit #[Bean] factory takes precedence over auto-wired interface
            // binding: registerBeans() runs first and, when a #[Bean] method's return
            // type IS this interface, already bound a Closure factory on $interface.
            // Without this guard, the default bind() below would silently clobber
            // that factory with the scanned implementation's class binding. A type
            // left CONTESTED by competing beans counts as claimed too: quietly
            // resolving it to a scanned component would hide the ambiguity rather
            // than report it.
            if (isset($beanBoundTypes[$interface])) {
                continue;
            }

            // Bind the interface to a single default: the #[Primary], else the sole implementation.
            //
            // The interface is bound to a single default ONLY when unambiguous:
            // exactly one #[Primary] implementation, or exactly one implementation
            // total. With zero-or-multiple #[Primary] implementations among
            // multiple candidates, the choice is inherently ambiguous, so the
            // interface is intentionally left UNBOUND — callers must resolve it
            // by name/qualifier or via the ordered tagged list (see tagFor()).
            $primary = array_values(array_filter($impls, static fn (ComponentDescriptor $c): bool => $c->primary));
            if (count($primary) === 1) {
                $this->container->bind($interface, $primary[0]->class);
            } elseif (count($impls) === 1) {
                $this->container->bind($interface, $impls[0]->class);
            }
            // Otherwise leave the interface unbound: ambiguous, must be resolved by name/qualifier.
        }
    }
}

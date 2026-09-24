<?php

declare(strict_types=1);

namespace Firefly\Context\Definition;

use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scanner\ComponentManifest;

/**
 * Pure in-memory storage for BeanDefinitions collected during the boot engine's definition stage
 * (BootPhase::ConditionPassOne through BootPhase::FlushDefinitions).
 *
 * MUST NOT touch the container — the definition stage and the instance stage are deliberately
 * split so that exactly one condition-filtered ComponentManifest is ever handed to the container
 * registrar (FlushDefinitionsPass).
 *
 * IT IS ALSO THE ONE DOOR EVERY DEFINITION COMES THROUGH, which is why the repair-boot filter below
 * lives here rather than at a consumer. EagerSingletonsPass has skipped a definition whose class no
 * longer exists since 26.09.1, and that guard was still not enough to let `firefly:cache` repair a
 * stale manifest: it protects the ONE pass it is written in, and a deleted #[Component] that implemented
 * an interface breaks the boot somewhere else entirely. ContainerRegistrar::wireInterfaces() binds the
 * INTERFACE to the missing class and tags it as an implementation, so the throw comes out of resolving a
 * bean that still exists and injects that contract — `Target class [...] does not exist` for a perfectly
 * live abstract, with the deleted class named nowhere near the failure. Three more consumers read a class
 * straight off the manifest with nothing between them and `make()`: RegisterBeanPostProcessorsPass
 * (phase 700, BEFORE eager singletons), InfrastructureStartPass, and RegisterEventListenersPass, whose
 * listener closure throws on the first dispatch rather than at boot.
 *
 * Filtering at the door closes all five at once, and leaves each consumer's own guard as depth rather
 * than as the only thing standing between a developer and `rm -rf bootstrap/cache/firefly`.
 */
final class BeanDefinitionRegistry
{
    /** @var list<BeanDefinition> */
    private array $definitions = [];

    /**
     * @param  StaleDefinitionReport  $stale  where a dropped definition is recorded so the running command can name it
     * @param  bool  $dropMissingClasses  ONLY a repair boot passes true — see add()
     */
    public function __construct(
        private readonly StaleDefinitionReport $stale = new StaleDefinitionReport,
        private readonly bool $dropMissingClasses = false,
    ) {}

    /**
     * Adds a definition — unless this is a repair boot and the definition names a class autoloading
     * cannot find, in which case it is dropped and recorded instead.
     *
     * WHY THE DROP IS SCOPED TO A REPAIR BOOT, and not applied to every boot. A compiled manifest that
     * names a class no longer on disk describes an application that has already moved on, and during
     * `firefly:cache` or `firefly:clear` that is the whole point: the command exists to replace or delete
     * the manifest it is booting from, it serves no request, and the fresh manifest it writes will not
     * contain the entry at all. Refusing to boot there is refusing to be repaired — which is exactly the
     * hole a developer fell into, and why the recovery was a three-step incantation (`rm` the cache
     * directory, `composer dump-autoload`, `firefly:cache`) rather than the one command that advertises
     * itself as the fix.
     *
     * Under EVERY OTHER command the definition is kept, and the boot fails exactly as loudly as it did
     * before. That asymmetry is deliberate and is the more important half of this change. A missing class
     * in a served process is not a stale cache, it is a broken deployment — a truncated artifact, a
     * classmap built from a different tree — and silently dropping the definition there would let the
     * application serve traffic with an interface quietly rebound to whichever implementation survived,
     * or with a BeanPostProcessor that never ran. A wrong answer nobody is told about is far worse than
     * the boot failure this change was written to remove.
     */
    public function add(BeanDefinition $definition): void
    {
        if ($this->dropMissingClasses && ! class_exists($definition->class())) {
            $this->stale->record($definition->class());

            return;
        }

        $this->definitions[] = $definition;
    }

    /** The classes this registry dropped, for whoever is in a position to report them. */
    public function stale(): StaleDefinitionReport
    {
        return $this->stale;
    }

    public function remove(string $class): void
    {
        $this->definitions = array_values(array_filter(
            $this->definitions,
            static fn (BeanDefinition $definition): bool => $definition->class() !== $class,
        ));
    }

    /**
     * @return list<BeanDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * True if any definition makes $type available: as its own class, as a declared interface, or
     * as the return type of a #[Bean] method. All three are ways a type becomes resolvable —
     * missing any of them would make #[ConditionalOnBean] silently wrong.
     */
    public function containsType(string $type): bool
    {
        foreach ($this->definitions as $definition) {
            if ($definition->class() === $type) {
                return true;
            }

            if (in_array($type, $definition->descriptor->interfaces, true)) {
                return true;
            }

            foreach ($definition->descriptor->beans as $bean) {
                if ($bean->returns === $type) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Builds a NEW ComponentManifest from the current definitions. Never mutates M2's manifest —
     * this is the single condition-filtered manifest FlushDefinitionsPass hands to
     * ContainerRegistrar::register().
     */
    public function toComponentManifest(): ComponentManifest
    {
        return new ComponentManifest(array_map(
            static fn (BeanDefinition $definition): ComponentDescriptor => $definition->descriptor,
            $this->definitions,
        ));
    }
}

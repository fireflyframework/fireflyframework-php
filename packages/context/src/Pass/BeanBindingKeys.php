<?php

declare(strict_types=1);

namespace Firefly\Context\Pass;

use Firefly\Container\Descriptor\BeanDescriptor;
use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Context\Definition\BeanDefinition;
use Firefly\Context\Definition\BeanDefinitionRegistry;

/**
 * Answers ONE question for the instance-stage passes: under which container key did
 * ContainerRegistrar actually bind this #[Bean] method's product?
 *
 * WHAT WAS BROKEN. Every instance-stage pass used to answer it with `$bean->returns`, and for a
 * long time that was right — the registrar bound each bean under its return type and treated the
 * #[Bean] name as a mere alias of it. It stopped being right when firefly/container learned
 * #[Primary]/#[Qualifier] for #[Bean] methods. The registrar's rule now (see
 * ContainerRegistrar::registerBeans(), which is the authority this class mirrors):
 *
 *  - ONE #[Bean] method produces the type — the overwhelmingly common case, unchanged: the factory
 *    is bound on the RETURN TYPE and the name, if any, is aliased to it. Return type is the key.
 *  - SEVERAL produce it (a CONTESTED type): each competitor is bound under its OWN #[Bean] NAME,
 *    and the bare type key becomes either an ALIAS of the #[Primary] winner or — with no
 *    #[Primary] — a factory that throws a NoUniqueBeanDefinition-style ConfigurationException.
 *    The name is the key; the return type is a key belonging to NO individual bean.
 *
 * Keying on `$bean->returns` regardless produced three distinct silent failures, one per pass:
 * EagerSingletonsPass built only the #[Primary] winner (or crashed boot outright when there was
 * none), RegisterBeanPostProcessorsPass extended only the winner's binding so every sibling
 * escaped the BeanPostProcessor chain, and RegisterEventListenersPass invoked a contested type's
 * listener through the type key, hitting the winner (or the throwing guard) rather than the bean
 * that declared it. All three now ask this class instead. See CompetingBeansPassTest, which pins
 * every one of them end-to-end through the REAL registrar.
 *
 * WHY THE COUNT IS TAKEN FROM THE SAME DESCRIPTORS THE PASS ITERATES. "Contested" has to mean
 * exactly what it meant to the registrar, or this class and the container disagree about the key.
 * FlushDefinitionsPass hands ContainerRegistrar::register() the manifest built by
 * BeanDefinitionRegistry::toComponentManifest() — a lossless array_map over the SAME, already
 * condition-filtered definitions every instance-stage pass then reads — so counting over those
 * descriptors reproduces the registrar's grouping exactly, #[ConditionalOn*]-removed #[Bean]
 * methods included.
 */
final class BeanBindingKeys
{
    /**
     * @param  array<string, int>  $producers  return type => how many #[Bean] methods produce it
     */
    private function __construct(private readonly array $producers) {}

    public static function fromDefinitions(BeanDefinitionRegistry $definitions): self
    {
        return self::fromDescriptors(array_map(
            static fn (BeanDefinition $definition): ComponentDescriptor => $definition->descriptor,
            $definitions->all(),
        ));
    }

    /**
     * @param  list<ComponentDescriptor>  $descriptors
     */
    public static function fromDescriptors(array $descriptors): self
    {
        /** @var array<string, int> $producers */
        $producers = [];

        foreach ($descriptors as $descriptor) {
            foreach ($descriptor->beans as $bean) {
                // A builtin or untyped return records '' (see ComponentScanner): the registrar
                // skips it entirely, so it never competes for anything and has no key at all.
                if ($bean->returns === '') {
                    continue;
                }

                $producers[$bean->returns] = ($producers[$bean->returns] ?? 0) + 1;
            }
        }

        return new self($producers);
    }

    /**
     * The container key this #[Bean] method's product is registered under, or null when it has no
     * key at all.
     *
     * Null has exactly two causes, both of which mean "there is nothing for a pass to resolve
     * here", never "resolve it some other way":
     *  - an untyped/builtin return (`$bean->returns === ''`), which the registrar never binds;
     *  - a competitor with no name. That shape cannot reach a booted application — the registrar
     *    rejects an anonymous competing bean at REGISTRATION time, because a bean reachable only
     *    through a return type its competitors already claim can never be resolved — so this arm
     *    exists to keep the null-safety honest rather than to handle a live case.
     */
    public function keyFor(BeanDescriptor $bean): ?string
    {
        if ($bean->returns === '') {
            return null;
        }

        return $this->isContested($bean->returns) ? $bean->name : $bean->returns;
    }

    /**
     * True when more than one surviving #[Bean] method produces $type — i.e. when the bare type key
     * belongs to the GROUP (alias of the #[Primary] winner, or the ambiguity guard) rather than to
     * any single bean.
     */
    public function isContested(string $type): bool
    {
        return ($this->producers[$type] ?? 0) > 1;
    }
}

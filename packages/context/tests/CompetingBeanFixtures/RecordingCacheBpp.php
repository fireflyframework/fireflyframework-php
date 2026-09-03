<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\CompetingBeanFixtures;

use Firefly\Container\Attributes\Component;
use Firefly\Context\Processor\BeanPostProcessor;

/**
 * Records every bean the composite extender chain actually runs, so a competing #[Bean] that
 * silently escapes the BeanPostProcessor chain shows up as a MISSING entry rather than as nothing
 * at all. This is the observable stand-in for TransactionalBeanPostProcessor: a bean that never
 * reaches a BPP is a bean whose #[Transactional] never applies.
 *
 * Keyed on CachePort::class ($declaredClass stays the DECLARED TYPE even for a bean bound under
 * its own name — see BeanPostProcessor's contract, and TransactionalBeanPostProcessor, which calls
 * class_exists() on it) and disambiguated by the product's own name(), which is the only thing
 * that distinguishes the two competitors at runtime.
 */
#[Component]
final class RecordingCacheBpp implements BeanPostProcessor
{
    public function __construct(private readonly CacheProbe $probe) {}

    public function beforeInitialization(object $bean, string $declaredClass): object
    {
        if ($bean instanceof CachePort) {
            $this->probe->record("bpp:before:{$bean->name()}:{$declaredClass}");
        }

        return $bean;
    }

    public function afterInitialization(object $bean, string $declaredClass): object
    {
        if ($bean instanceof CachePort) {
            $this->probe->record("bpp:after:{$bean->name()}:{$declaredClass}");
        }

        return $bean;
    }
}

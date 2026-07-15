<?php

declare(strict_types=1);

namespace Firefly\Context\Processor;

/**
 * Spring's BeanPostProcessor: a hook into the two initialization passes every bean goes through
 * after construction (see BeanPostProcessorChain for the exact per-bean sequence).
 *
 * Both methods return the (possibly replaced) bean. Returning a DIFFERENT object than the one
 * received REPLACES the bean for the rest of the pipeline — this is the interception seam M9
 * (#[Transactional]) and M12 (#[PreAuthorize]) use to substitute a proxy for the original bean.
 * Corollary: a proxy MUST only ever be created from afterInitialization() (pass 2) — by then
 * #[PostConstruct] has already run against the real, pre-proxy instance.
 *
 * $declaredClass is the class recorded on the bean's DEFINITION, which the caller threads through
 * — never $bean::class. Once a bean has been replaced by a proxy, $bean::class is the proxy's
 * class, which has no entry in the component manifest.
 */
interface BeanPostProcessor
{
    public function beforeInitialization(object $bean, string $declaredClass): object;

    public function afterInitialization(object $bean, string $declaredClass): object;
}

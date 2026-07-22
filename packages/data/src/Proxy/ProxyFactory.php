<?php

declare(strict_types=1);

namespace Firefly\Data\Proxy;

use Closure;
use Firefly\Data\Transaction\TransactionInterceptor;
use ReflectionClass;

/**
 * State-preserving proxy instantiation — the ONE runtime reflection site in packages/data/src. Called from
 * TransactionalBeanPostProcessor::afterInitialization() (pass 2), so the real bean's #[PostConstruct] has already
 * run and its post-init state is what gets copied.
 *
 *   1. newInstanceWithoutConstructor() — the proxy declares NO constructor, so #[PostConstruct] is NOT re-run.
 *   2. A Closure bound to $declaredClass copies the bean's scope-visible state (public/protected/private of
 *      $declaredClass) via get_object_vars — NOT ReflectionProperty. readonly properties left uninitialized by
 *      (1) accept this one in-scope initialization.
 *   3. A Closure bound to $proxyClass sets the proxy's own private $__fireflyTxInterceptor.
 *
 * KNOWN LATENT (design §7): state private to a PARENT of $declaredClass is not visible to the scoped closure;
 * typical services/repositories hold their own private fields and are unaffected. The proxy IS-A $declaredClass,
 * so $container->call([$proxy, …]) and #[PreDestroy] resolve against it.
 */
final class ProxyFactory
{
    /**
     * @param  class-string  $declaredClass
     * @param  class-string  $proxyClass
     */
    public function wrap(object $bean, string $declaredClass, string $proxyClass, TransactionInterceptor $interceptor): object
    {
        $proxy = (new ReflectionClass($proxyClass))->newInstanceWithoutConstructor();

        /** @var Closure(object): void $copyState */
        $copyState = Closure::bind(function (object $source): void {
            foreach (get_object_vars($source) as $property => $value) {
                $this->$property = $value;
            }
        }, $proxy, $declaredClass);
        $copyState($bean);

        // The property name is data (as in the state copy above): the proxy's private $__fireflyTxInterceptor is
        // invisible to this file's scope, so it is written dynamically through a closure bound to $proxyClass.
        /** @var Closure(string, TransactionInterceptor): void $setInterceptor */
        $setInterceptor = Closure::bind(function (string $property, TransactionInterceptor $interceptor): void {
            $this->$property = $interceptor;
        }, $proxy, $proxyClass);
        $setInterceptor('__fireflyTxInterceptor', $interceptor);

        return $proxy;
    }
}

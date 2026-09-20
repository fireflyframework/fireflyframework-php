<?php

declare(strict_types=1);

namespace Firefly\Data\Proxy;

use Closure;
use Firefly\Data\Transaction\TransactionInterceptor;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
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
 *   3. A Closure bound to $proxyClass sets ONE private interceptor property per advice the proxy runs — read
 *      off the generated `__fireflyAdvice()` table — from the map the post-processor resolved. The
 *      transactional interceptor is the explicit fourth parameter (the signature every proxy test pins); every
 *      other advice arrives keyed by id in $interceptors, and an advice the proxy declares but nobody supplied
 *      is a ConfigurationException rather than an uninitialised typed property blowing up on first call.
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
     * @param  array<string, MethodInterceptor>  $interceptors  advice id => interceptor, for every advice but the transactional one
     */
    public function wrap(object $bean, string $declaredClass, string $proxyClass, TransactionInterceptor $interceptor, array $interceptors = []): object
    {
        $proxy = (new ReflectionClass($proxyClass))->newInstanceWithoutConstructor();

        /** @var Closure(object): void $copyState */
        $copyState = Closure::bind(function (object $source): void {
            foreach (get_object_vars($source) as $property => $value) {
                $this->$property = $value;
            }
        }, $proxy, $declaredClass);
        $copyState($bean);

        $interceptors[Advice::TRANSACTIONAL] = $interceptor;

        // A proxy generated before the advice table existed runs the transactional advice alone. The table is
        // read through a callable array rather than `$proxyClass::__fireflyAdvice()` so the call is honest to
        // static analysis: $proxyClass is an arbitrary class-string.
        $table = [$proxyClass, '__fireflyAdvice'];
        /** @var array<string, class-string> $advice */
        $advice = is_callable($table) ? $table() : [Advice::TRANSACTIONAL => TransactionInterceptor::class];

        // The property names are data (as in the state copy above): the proxy's private members are invisible
        // to this file's scope, so they are written dynamically through a closure bound to $proxyClass.
        /** @var Closure(string, MethodInterceptor): void $setInterceptor */
        $setInterceptor = Closure::bind(function (string $property, MethodInterceptor $interceptor): void {
            $this->$property = $interceptor;
        }, $proxy, $proxyClass);

        foreach (array_keys($advice) as $id) {
            $link = $interceptors[$id] ?? throw new ConfigurationException("Proxy [{$proxyClass}] runs the [{$id}] advice but no interceptor was supplied for it.");
            $setInterceptor('__firefly'.ucfirst($id).'Interceptor', $link);
        }

        return $proxy;
    }
}

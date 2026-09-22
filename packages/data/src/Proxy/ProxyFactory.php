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
 *   2. The bean's initialised state is copied slot by slot, each slot written by a Closure bound to the class
 *      that DECLARES it — never ReflectionProperty::setValue(). The `(array)` cast is what makes the slots exact:
 *      it mangles a private as "\0Owner\0name" and a protected as "\0*\0name", so a private that a parent and a
 *      child both declare under one name is two slots, each written from its own scope, and a typed property the
 *      bean never initialised is absent and stays uninitialised on the proxy. The declaring class of a
 *      public/protected slot comes from the ReflectionClass this file already holds.
 *   3. A Closure bound to $proxyClass sets ONE private interceptor property per advice the proxy runs — read
 *      off the generated `__fireflyAdvice()` table — from the map the post-processor resolved. The
 *      transactional interceptor is the explicit fourth parameter (the signature every proxy test pins); every
 *      other advice arrives keyed by id in $interceptors, and an advice the proxy declares but nobody supplied
 *      is a ConfigurationException rather than an uninitialised typed property blowing up on first call.
 *
 * WHY THE DECLARING CLASS, NOT $declaredClass: a closure bound to $declaredClass sees only that class's own
 * privates, so a `private` on a parent — EloquentRepository's PersistenceExceptionTranslator under every
 * #[Repository] — was never copied and the proxy threw "must not be accessed before initialization" from the
 * parent's own methods. And on the PHP 8.3 floor a readonly property is initialisable from its declaring class's
 * scope alone (8.4 relaxed it to protected(set)), so the child-scoped copy of a parent's `protected readonly`
 * — the repository's $manifest and $tracker — was a scope Error at wrap time there. Both are the same rule:
 * every slot is written from where it was declared. The proxy IS-A $declaredClass, so $container->call([$proxy,
 * …]) and #[PreDestroy] resolve against it.
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

        foreach ($this->stateByDeclaringClass($bean, $declaredClass) as $scope => $values) {
            /** @var Closure(array<string, mixed>): void $copyState */
            $copyState = Closure::bind(function (array $values): void {
                foreach ($values as $property => $value) {
                    $this->$property = $value;
                }
            }, $proxy, $scope);
            $copyState($values);
        }

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

    /**
     * The bean's initialised instance state, grouped by the class each slot must be written from: a private's
     * owner is in its mangled name; a public/protected slot belongs to the class that declares (or redeclares)
     * it; a dynamic property, declared nowhere, is written from $declaredClass as before.
     *
     * @param  class-string  $declaredClass
     * @return array<class-string, array<string, mixed>>
     */
    private function stateByDeclaringClass(object $bean, string $declaredClass): array
    {
        $declared = new ReflectionClass($declaredClass);
        /** @var array<class-string, array<string, mixed>> $state */
        $state = [];

        foreach ((array) $bean as $mangled => $value) {
            $mangled = (string) $mangled;

            if ($mangled !== '' && $mangled[0] === "\0") {
                [, $owner, $name] = explode("\0", $mangled, 3);
                /** @var class-string $scope */
                $scope = $owner === '*' ? $this->declaringClass($declared, $name) : $owner;
            } else {
                $name = $mangled;
                $scope = $this->declaringClass($declared, $name);
            }

            $state[$scope][$name] = $value;
        }

        return $state;
    }

    /**
     * @param  ReflectionClass<object>  $declared
     * @return class-string
     */
    private function declaringClass(ReflectionClass $declared, string $name): string
    {
        return $declared->hasProperty($name)
            ? $declared->getProperty($name)->getDeclaringClass()->getName()
            : $declared->getName();
    }
}

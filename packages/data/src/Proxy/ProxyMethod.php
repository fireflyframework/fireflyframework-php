<?php

declare(strict_types=1);

namespace Firefly\Data\Proxy;

/**
 * A single proxied method's generation input: its name, its rendered signature (param source + call-arg
 * source + return type, all pre-rendered by the sanctioned TransactionalScanner so the generator stays
 * reflection-free) and the ORDERED advice it runs, each with the descriptor literal baked into the proxy.
 */
final readonly class ProxyMethod
{
    /**
     * @param  list<BoundAdvice>  $advice  outermost first
     */
    public function __construct(
        public string $name,
        public string $paramSource,
        public string $argSource,
        public string $returnType,
        public array $advice,
    ) {}
}

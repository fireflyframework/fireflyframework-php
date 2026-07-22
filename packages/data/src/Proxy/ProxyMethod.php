<?php

declare(strict_types=1);

namespace Firefly\Data\Proxy;

use Firefly\Data\Transaction\TransactionalDescriptor;

/**
 * A single transactional method's generation input: its name, its rendered signature (param source + call-arg
 * source + return type, all pre-rendered by the sanctioned TransactionalScanner so the generator stays
 * reflection-free) and its effective TransactionalDescriptor (baked into the proxy as literals).
 */
final readonly class ProxyMethod
{
    public function __construct(
        public string $name,
        public string $paramSource,
        public string $argSource,
        public string $returnType,
        public TransactionalDescriptor $descriptor,
    ) {}
}

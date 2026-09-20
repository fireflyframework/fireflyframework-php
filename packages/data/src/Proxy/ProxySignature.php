<?php

declare(strict_types=1);

namespace Firefly\Data\Proxy;

/** A method's rendered signature: parameter source, call-argument source and return type, as TransactionalScanner renders them. */
final readonly class ProxySignature
{
    public function __construct(
        public string $paramSource,
        public string $argSource,
        public string $returnType,
    ) {}
}

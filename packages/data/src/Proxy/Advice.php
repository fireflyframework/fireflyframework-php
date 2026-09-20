<?php

declare(strict_types=1);

namespace Firefly\Data\Proxy;

use Firefly\Data\Transaction\TransactionalDescriptor;
use Firefly\Data\Transaction\TransactionInterceptor;
use InvalidArgumentException;

/**
 * One KIND of advice a proxy may run (Spring's Advisor, minus the pointcut — the plan already says which
 * methods): the id that names the proxy's private members, the interceptor bean the factory injects, the
 * descriptor class the interceptor reads off the invocation, and the order. Lower runs OUTER: Security's
 * advice is 100 and the transactional one is 1000, so a refusal is thrown before a transaction is ever opened
 * — the same ordering Spring documents for @EnableMethodSecurity and @EnableTransactionManagement.
 *
 * The id is a lower-case identifier because it is spliced into PHP member names (`__fireflyTxInterceptor`,
 * `__fireflyTxDescriptor`) and into the compiled plan; the constructor refuses anything else so a bad id fails
 * at scan time and never as a parse error inside a generated class.
 */
final readonly class Advice
{
    public const string TRANSACTIONAL = 'tx';

    /**
     * @param  class-string<MethodInterceptor>  $interceptorClass
     * @param  class-string  $descriptorClass
     */
    public function __construct(
        public string $id,
        public string $interceptorClass,
        public string $descriptorClass,
        public int $order,
    ) {
        if (preg_match('/^[a-z][a-z0-9]*$/', $id) !== 1) {
            throw new InvalidArgumentException("Advice id [{$id}] must be a lower-case identifier (a-z, 0-9).");
        }
    }

    public static function transactional(): self
    {
        return new self(self::TRANSACTIONAL, TransactionInterceptor::class, TransactionalDescriptor::class, 1000);
    }

    /** The proxy's private interceptor property for this advice. */
    public function property(): string
    {
        return '__firefly'.ucfirst($this->id).'Interceptor';
    }

    /** The proxy's private static descriptor factory for this advice. */
    public function factory(): string
    {
        return '__firefly'.ucfirst($this->id).'Descriptor';
    }

    /**
     * @return array{id: string, interceptor: string, descriptor: string, order: int}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'interceptor' => $this->interceptorClass, 'descriptor' => $this->descriptorClass, 'order' => $this->order];
    }

    /**
     * @param  array{id: string, interceptor: string, descriptor: string, order: int}  $row
     */
    public static function fromArray(array $row): self
    {
        /** @var class-string<MethodInterceptor> $interceptor */
        $interceptor = $row['interceptor'];
        /** @var class-string $descriptor */
        $descriptor = $row['descriptor'];

        return new self($row['id'], $interceptor, $descriptor, $row['order']);
    }
}

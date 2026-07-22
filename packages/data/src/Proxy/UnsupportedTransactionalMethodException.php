<?php

declare(strict_types=1);

namespace Firefly\Data\Proxy;

use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * Thrown at SCAN/GENERATE time (compile-time — firefly:cache, or inline in tests) when a #[Transactional] method
 * declares a by-reference parameter (`&$out`). The generated proxy wraps the call in `fn () => parent::m($out)`,
 * an arrow closure that captures `$out` BY VALUE — so the caller's variable is never written back and the
 * mutation is SILENTLY dropped. Rather than lose data at runtime we FAIL LOUD here, naming the offending
 * class::method. Wrap the value in an object/DTO and mutate that instead. Extends the kernel ConfigurationException
 * (Data -> Kernel is an allowed edge, mirroring TransactionRequiredException's InfrastructureException reuse).
 *
 * This class is reflection-free: the by-reference DETECTION (an is-passed-by-reference check) lives in the
 * sanctioned TransactionalScanner, never here.
 */
final class UnsupportedTransactionalMethodException extends ConfigurationException
{
    public static function byReferenceParameter(string $class, string $method, string $parameter): self
    {
        return new self(
            sprintf(
                '%s::%s() declares by-reference parameter $%s: by-reference parameters are not supported on '
                .'#[Transactional] methods (the interceptor closure captures it by value; wrap the value in an '
                .'object/DTO instead).',
                $class,
                $method,
                $parameter,
            ),
            'UNSUPPORTED_TRANSACTIONAL_METHOD',
        );
    }
}

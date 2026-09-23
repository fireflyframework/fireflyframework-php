<?php

declare(strict_types=1);

namespace Firefly\Data\Proxy;

use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * Thrown at SCAN/GENERATE time (compile-time — firefly:cache, or inline in tests) for the three method shapes a
 * generated proxy cannot honour, each named by class::method so the manifest row is still in view:
 *
 *   - a by-reference parameter (`&$out`): the proxy wraps the call in `fn () => parent::m($out)`, an arrow
 *     closure that captures `$out` BY VALUE, so the caller's variable is never written back and the mutation is
 *     SILENTLY dropped. Wrap the value in an object/DTO and mutate that instead;
 *   - a `final` CLASS: the proxy extends its target;
 *   - a `final` METHOD: the proxy OVERRIDES every planned method, so PHP refuses the generated class outright.
 *
 * The last two would otherwise both be fatals at the `require` of a generated file — "Cannot override final
 * method" mentions neither the attribute that planned the method nor the class-level rule that may have fanned
 * onto it — which is the whole reason they are refused where the plan is still readable. Extends the kernel
 * ConfigurationException (Data -> Kernel is an allowed edge, mirroring TransactionRequiredException's
 * InfrastructureException reuse).
 *
 * This class is reflection-free: every DETECTION (is-passed-by-reference, is-final) lives in the sanctioned
 * TransactionalScanner, never here.
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

    public static function finalClass(string $class): self
    {
        return new self(
            sprintf(
                '%s is final, and a proxied method needs a subclass: remove `final` from the class, or move the '
                .'#[Transactional] / method-security attribute onto a non-final collaborator.',
                $class,
            ),
            'UNSUPPORTED_TRANSACTIONAL_METHOD',
        );
    }

    public static function finalMethod(string $class, string $method): self
    {
        return new self(
            sprintf(
                '%s::%s() is final, and a proxied method must be OVERRIDDEN: the generated subclass would be '
                .'refused by PHP at load with "Cannot override final method", naming neither the attribute nor '
                .'the class-level rule that planned it. Remove `final` from the method, or move the advice onto '
                .'a method the proxy can override.',
                $class,
                $method,
            ),
            'UNSUPPORTED_TRANSACTIONAL_METHOD',
        );
    }
}

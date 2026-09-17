<?php

declare(strict_types=1);

namespace Firefly\Security\Access\Method;

use Firefly\Kernel\Exception\Security\AuthorizationException;
use Firefly\Security\Access\Expression\SecurityExpressionEvaluator;
use Firefly\Security\Core\Authentication;
use Psr\Log\LoggerInterface;

/**
 * What an authenticated principal is told when a method-security rule refuses them — built in one place so
 * the web guard and the CQRS enforcer, which used to each format their own "Access is denied for
 * [Class::method]." sentence, cannot drift apart.
 *
 * THE CLASS NAME LEAVES THE WIRE AND GOES TO THE LOG. "Access is denied for [App\Runs\Http\RunController::
 * start]." is the right diagnostic for an operator and the wrong sentence for a person: it names a PHP class
 * and a method to someone who has no idea what either is, and one real application's consultant read it
 * verbatim on a panel. The class, the method, the principal and the authorities the rule asked for are logged
 * at warning — a refusal is worth a log line, and it is the line an operator greps for — and the wire gets
 * either the framework's sentence or the rule's own `code`/`message` from its #[PreAuthorize], plus the
 * authorities as an RFC 9457 extension member so a client can render "you need ROLE_MANAGER" without ever
 * seeing the expression.
 *
 * The logger is optional because both callers can be constructed by hand in a test or by a container that
 * has no PSR logger bound; a refusal must still refuse when nobody is listening.
 */
final class MethodSecurityRefusal
{
    /** The framework's sentence for a refused, authenticated principal, when the rule supplies none. */
    public const string SENTENCE = 'You do not have permission to do this.';

    public function __construct(
        private readonly SecurityExpressionEvaluator $evaluator,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function refuse(SecurityMethodDescriptor $rule, Authentication $authentication): AuthorizationException
    {
        $required = $this->evaluator->authorities($rule->expression);

        $this->logger?->warning(
            "Method security refused {$rule->class}::{$rule->method}.",
            [
                'class' => $rule->class,
                'method' => $rule->method,
                'principal' => $authentication->getName(),
                'authorities' => $authentication->authorityStrings(),
                'requiredAuthorities' => $required,
                'expression' => $rule->expression,
            ],
        );

        return (new AuthorizationException($rule->message ?? self::SENTENCE, $rule->code ?? 'ACCESS_DENIED'))
            ->withExtensions(['requiredAuthorities' => $required]);
    }
}

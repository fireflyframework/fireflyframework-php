<?php

declare(strict_types=1);

use Firefly\Cqrs\Handler\HandlerDescriptor;
use Firefly\Cqrs\Handler\HandlerKind;
use Firefly\Cqrs\Handler\HandlerManifest;
use Firefly\Kernel\Exception\Security\AuthorizationException;
use Firefly\Security\Access\DenyAllPermissionEvaluator;
use Firefly\Security\Access\Expression\SecurityExpressionEvaluator;
use Firefly\Security\Access\Method\SecurityMethodDescriptor;
use Firefly\Security\Access\Method\SecurityMethodManifest;
use Firefly\Security\Access\RoleHierarchy;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Firefly\Security\Cqrs\MethodSecurityMessageEnforcer;
use Firefly\Security\Cqrs\SecurityCommandAuthorizer;
use Firefly\Security\Cqrs\SecurityQueryAuthorizer;
use Firefly\Security\Tests\Fixtures\Cqrs\AdminCommand;
use Firefly\Security\Tests\Fixtures\Cqrs\AdminCommandHandler;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

afterEach(fn () => SecurityContextHolder::clearContext());

/**
 * @param  list<SecurityMethodDescriptor>  $rules
 */
function commandEnforcer(array $rules): MethodSecurityMessageEnforcer
{
    $handlers = new HandlerManifest(
        [new HandlerDescriptor(AdminCommand::class, AdminCommandHandler::class, 'handle', HandlerKind::Command)],
        [],
    );

    return new MethodSecurityMessageEnforcer(
        $handlers,
        new SecurityMethodManifest($rules),
        new SecurityExpressionEvaluator,
        RoleHierarchy::fromRules([]),
        new DenyAllPermissionEvaluator,
    );
}

function commandAuthorizer(): SecurityCommandAuthorizer
{
    return new SecurityCommandAuthorizer(commandEnforcer([
        new SecurityMethodDescriptor(AdminCommandHandler::class, 'handle', "hasRole('ADMIN')", ['command']),
    ]));
}

it('denies a command when the handler rule is not satisfied', function () {
    SecurityContextHolder::setContext(new SecurityContext(
        Authentication::authenticated('u', 'u', [new SimpleGrantedAuthority('ROLE_USER')])
    ));

    commandAuthorizer()->authorize(new AdminCommand);
})->throws(AuthorizationException::class);

it('allows a command when the handler rule is satisfied', function () {
    SecurityContextHolder::setContext(new SecurityContext(
        Authentication::authenticated('a', 'a', [new SimpleGrantedAuthority('ROLE_ADMIN')])
    ));

    commandAuthorizer()->authorize(new AdminCommand);
    expect(true)->toBeTrue(); // no throw
});

it('allows a command whose handler carries no rule', function () {
    $authorizer = new SecurityCommandAuthorizer(commandEnforcer([]));

    $authorizer->authorize(new AdminCommand);
    expect(true)->toBeTrue();
});

it('allows a message whose class has no registered handler at all', function () {
    // Empty HandlerManifest: the enforcer's handlerByMessage index is empty, so the lookup misses entirely —
    // this hits the handler-absent `return;` in MethodSecurityMessageEnforcer::enforce, distinct from the
    // "handler found but no rule" path covered above (which still registers AdminCommandHandler).
    $enforcer = new MethodSecurityMessageEnforcer(
        new HandlerManifest([], []),
        new SecurityMethodManifest([]),
        new SecurityExpressionEvaluator,
        RoleHierarchy::fromRules([]),
        new DenyAllPermissionEvaluator,
    );

    (new SecurityCommandAuthorizer($enforcer))->authorize(new AdminCommand);
})->throwsNoExceptions();

it('does not leak a command-kind rule onto the query authorizer for the same message class', function () {
    // Registers AdminCommand => AdminCommandHandler::handle as a COMMAND-kind handler with a restrictive rule.
    // The enforcer indexes handlers by "kind:messageClass", so the QUERY authorizer looking up the same
    // message class must miss (no "query:...AdminCommand" entry) and allow — proving the kind prefix prevents
    // a Command-side rule from being enforced on the Query side. ROLE_USER (no ADMIN) means a regression that
    // dropped/ignored the kind prefix would make this throw.
    SecurityContextHolder::setContext(new SecurityContext(
        Authentication::authenticated('u', 'u', [new SimpleGrantedAuthority('ROLE_USER')])
    ));

    $enforcer = commandEnforcer([
        new SecurityMethodDescriptor(AdminCommandHandler::class, 'handle', "hasRole('ADMIN')", ['command']),
    ]);

    (new SecurityQueryAuthorizer($enforcer))->authorize(new AdminCommand);
})->throwsNoExceptions();

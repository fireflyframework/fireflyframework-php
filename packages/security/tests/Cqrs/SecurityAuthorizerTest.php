<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Cqrs\Handler\HandlerDescriptor;
use Firefly\Cqrs\Handler\HandlerKind;
use Firefly\Cqrs\Handler\HandlerManifest;
use Firefly\Kernel\Exception\Security\AuthenticationException;
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
use Illuminate\Config\Repository;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

afterEach(fn () => SecurityContextHolder::clearContext());

/** The master flag on, as it is wherever the enforcer bean exists; a test flips it on this repository to prove the live read. */
function busSecurityConfig(): Config
{
    return new Config(new Repository(['firefly' => ['security' => ['enabled' => true]]]));
}

/**
 * @param  list<SecurityMethodDescriptor>  $rules
 */
function commandEnforcer(array $rules, ?Config $config = null): MethodSecurityMessageEnforcer
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
        $config ?? busSecurityConfig(),
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

it('refuses a command from nobody with a 401, not the 403 the enforcer used to answer on its own', function () {
    // No context at all: the enforcer now goes through MethodSecurityEvaluator, which says "authenticate first"
    // for an anonymous caller — the same answer the dispatcher guard and the proxy interceptor give.
    try {
        commandAuthorizer()->authorize(new AdminCommand);
        throw new LogicException('not refused');
    } catch (AuthenticationException $e) {
        expect($e->errorCode())->toBe('AUTHENTICATION_FAILED')
            ->and($e->getMessage())->toBe('Authentication is required.');
    }
});

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
        busSecurityConfig(),
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

it('refuses a command with the client sentence and the required authorities, not the handler class', function () {
    SecurityContextHolder::setContext(new SecurityContext(
        Authentication::authenticated('u', 'u', [new SimpleGrantedAuthority('ROLE_USER')])
    ));

    try {
        commandAuthorizer()->authorize(new AdminCommand);
        throw new LogicException('not refused');
    } catch (AuthorizationException $e) {
        expect($e->getMessage())->not->toContain('AdminCommandHandler')
            ->not->toContain('\\')
            ->and($e->extensions())->toBe(['requiredAuthorities' => ['ROLE_ADMIN']]);
    }
});

it('reads the master flag live: off lets the secured command through, and back on refuses it again', function () {
    // The buses hold their authorizer by constructor, so the ONLY way withoutSecurity() (or any config flip after
    // boot) can reach a built bus is for the enforcer to consult the flag on every message, as the proxy link does.
    $repository = new Repository(['firefly' => ['security' => ['enabled' => true]]]);
    $authorizer = new SecurityCommandAuthorizer(commandEnforcer([
        new SecurityMethodDescriptor(AdminCommandHandler::class, 'handle', "hasRole('ADMIN')", ['command']),
    ], new Config($repository)));

    expect(fn () => $authorizer->authorize(new AdminCommand))->toThrow(AuthenticationException::class);

    $repository->set('firefly.security.enabled', false);
    $authorizer->authorize(new AdminCommand);

    $repository->set('firefly.security.enabled', true);
    expect(fn () => $authorizer->authorize(new AdminCommand))->toThrow(AuthenticationException::class);
});

it('keeps enforcing at the bus when only firefly.security.method.enabled is off, as documented: that flag stands down the proxy link alone', function () {
    $config = new Config(new Repository(['firefly' => ['security' => ['enabled' => true, 'method' => ['enabled' => false]]]]));
    $authorizer = new SecurityCommandAuthorizer(commandEnforcer([
        new SecurityMethodDescriptor(AdminCommandHandler::class, 'handle', "hasRole('ADMIN')", ['command']),
    ], $config));

    $authorizer->authorize(new AdminCommand);
})->throws(AuthenticationException::class);

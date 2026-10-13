<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Cqrs\Command\DefaultCommandBus;
use Firefly\Cqrs\Correlation\CorrelationContext;
use Firefly\Cqrs\Exception\CommandProcessingException;
use Firefly\Cqrs\Handler\HandlerDescriptor;
use Firefly\Cqrs\Handler\HandlerKind;
use Firefly\Cqrs\Handler\HandlerManifest;
use Firefly\Cqrs\Handler\HandlerRegistry;
use Firefly\Cqrs\Metrics\NoOpCqrsMetrics;
use Firefly\Cqrs\Validation\MessageValidator;
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
use Firefly\Security\Tests\Fixtures\Cqrs\AdminCommand;
use Firefly\Security\Tests\Fixtures\Cqrs\AdminCommandHandler;
use Firefly\Security\Web\MethodSecurityControllerGuard;
use Firefly\Web\Exception\ProblemDetailsRenderer;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;

/*
 | ONE REFUSAL, ONE DOCUMENT, WHICHEVER SEAM REFUSED.
 |
 | MethodSecurityRefusal builds the AuthorizationException both the controller guard and the bus enforcer raise,
 | and it puts the authorities the rule asked for on it as the `requiredAuthorities` extension member
 | (docs/modules/security.md). A controller refusal reaches ProblemDetailsRenderer as itself; a command refusal
 | reaches it inside the CommandProcessingException DefaultCommandBus wraps every failure in. The wrapper copied
 | the cause's code, status, category and severity but not its extension members, so the same rule, refusing
 | the same principal, published `requiredAuthorities` from a controller and dropped it from a command.
 |
 | These render both refusals through the real renderer and compare the documents. `detail` is the one member
 | allowed to differ: the bus prefixes the cause's sentence with the command it was processing.
 */

uses(TestCase::class);

afterEach(fn () => SecurityContextHolder::clearContext());

/**
 * A real DefaultCommandBus whose authorizer is the real SecurityCommandAuthorizer over one rule on AdminCommand.
 */
function busRefusalCommandBus(SecurityMethodDescriptor $rule): DefaultCommandBus
{
    $enforcer = new MethodSecurityMessageEnforcer(
        new HandlerManifest(
            [new HandlerDescriptor(AdminCommand::class, AdminCommandHandler::class, 'handle', HandlerKind::Command)],
            [],
        ),
        new SecurityMethodManifest([$rule]),
        new SecurityExpressionEvaluator,
        RoleHierarchy::fromRules([]),
        new DenyAllPermissionEvaluator,
        new Config(new Repository(['firefly' => ['security' => ['enabled' => true]]])),
    );

    $registry = new HandlerRegistry;
    // The handler is never reached in these tests — the authorizer refuses first — so it only has to exist.
    $registry->registerCommandHandler(AdminCommand::class, fn (object $command): string => $command instanceof AdminCommand
        ? (new AdminCommandHandler)->handle($command)
        : throw new LogicException('not an AdminCommand'));

    return new DefaultCommandBus(
        $registry,
        new MessageValidator(null),
        new SecurityCommandAuthorizer($enforcer),
        new CorrelationContext,
        new NoOpCqrsMetrics,
    );
}

/**
 * The problem document the renderer publishes for a throwable, as a decoded array.
 *
 * @return array<string, mixed>
 */
function busRefusalDocument(Throwable $e): array
{
    $response = (new ProblemDetailsRenderer)->render($e, Request::create('/refused', 'POST'));

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

    return $payload;
}

/**
 * Refuses the same expression twice, once through the controller guard and once through the command bus, and
 * returns both refusals.
 *
 * @return array{0: Throwable, 1: Throwable}
 */
function busRefusalBothWays(string $expression, ?string $code = null, ?string $message = null): array
{
    SecurityContextHolder::setContext(new SecurityContext(
        Authentication::authenticated('u', 'u', [new SimpleGrantedAuthority('ROLE_USER')])
    ));

    $controller = null;
    try {
        (new MethodSecurityControllerGuard(
            new SecurityMethodManifest([new SecurityMethodDescriptor('App\\Ctrl', 'admin', $expression, [], $code, $message)]),
            new SecurityExpressionEvaluator, RoleHierarchy::fromRules([]), new DenyAllPermissionEvaluator,
        ))->check('App\\Ctrl', 'admin', []);
    } catch (AuthorizationException $e) {
        $controller = $e;
    }

    $bus = null;
    try {
        busRefusalCommandBus(new SecurityMethodDescriptor(AdminCommandHandler::class, 'handle', $expression, ['command'], $code, $message))
            ->send(new AdminCommand);
    } catch (CommandProcessingException $e) {
        $bus = $e;
    }

    expect($controller)->toBeInstanceOf(AuthorizationException::class)
        ->and($bus)->toBeInstanceOf(CommandProcessingException::class)
        ->and($bus?->getPrevious())->toBeInstanceOf(AuthorizationException::class);

    /** @var array{0: Throwable, 1: Throwable} */
    return [$controller, $bus];
}

/**
 * The document minus the members that describe the request rather than the refusal.
 *
 * @param  array<string, mixed>  $document
 * @return array<string, mixed>
 */
function busRefusalIdentity(array $document): array
{
    return array_diff_key($document, array_flip(['detail', 'instance', 'traceId', 'correlationId', 'timestamp']));
}

it('publishes requiredAuthorities for a refused command exactly as it does for a refused controller method', function () {
    [$controller, $bus] = busRefusalBothWays("hasRole('ADMIN') or hasAuthority('orders:write')");

    $fromController = busRefusalDocument($controller);
    $fromBus = busRefusalDocument($bus);

    expect($fromController['requiredAuthorities'] ?? null)->toBe(['ROLE_ADMIN', 'orders:write'])
        ->and($fromBus['requiredAuthorities'] ?? null)->toBe(['ROLE_ADMIN', 'orders:write'])
        ->and(busRefusalIdentity($fromBus))->toBe(busRefusalIdentity($fromController))
        ->and($fromBus['status'])->toBe(403)
        ->and($fromBus['code'])->toBe('ACCESS_DENIED');
});

it('keeps the rule\'s own code and sentence on a refused command, and only prefixes the sentence', function () {
    [$controller, $bus] = busRefusalBothWays("hasAnyRole('MANAGER', 'TENANT_ADMIN')", 'RUN_ROLE_REQUIRED', 'Only a manager may start a run.');

    $fromController = busRefusalDocument($controller);
    $fromBus = busRefusalDocument($bus);

    expect(busRefusalIdentity($fromBus))->toBe(busRefusalIdentity($fromController))
        ->and($fromBus['code'])->toBe('RUN_ROLE_REQUIRED')
        ->and($fromBus['requiredAuthorities'] ?? null)->toBe(['ROLE_MANAGER', 'ROLE_TENANT_ADMIN'])
        ->and($fromController['detail'])->toBe('Only a manager may start a run.')
        ->and($fromBus['detail'])->toBe('Processing command ['.AdminCommand::class.'] failed: Only a manager may start a run.');
});

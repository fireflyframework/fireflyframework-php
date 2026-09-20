<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Security\AuthenticationException;
use Firefly\Kernel\Exception\Security\AuthorizationException;
use Firefly\Security\Access\Expression\SecurityExpressionEvaluator;
use Firefly\Security\Access\Method\MethodSecurityEvaluator;
use Firefly\Security\Access\Method\SecurityMethodDescriptor;
use Firefly\Security\Access\RoleHierarchy;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Firefly\Security\Event\AuthenticationEventPublisher;
use Firefly\Security\Tests\Fixtures\Advice\OwnerPermissionEvaluator;
use Firefly\Security\Tests\Fixtures\Advice\Report;
use Firefly\Testing\Double\RecordingAuthenticationEvents;
use Illuminate\Support\Collection;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

afterEach(fn () => SecurityContextHolder::clearContext());

function evaluatorWith(RecordingAuthenticationEvents $events): MethodSecurityEvaluator
{
    return new MethodSecurityEvaluator(new SecurityExpressionEvaluator, RoleHierarchy::fromRules([]), new OwnerPermissionEvaluator, new AuthenticationEventPublisher($events));
}

function signInAs(string $name, string ...$authorities): void
{
    SecurityContextHolder::setContext(new SecurityContext(Authentication::authenticated($name, $name, array_values(array_map(
        static fn (string $a): SimpleGrantedAuthority => new SimpleGrantedAuthority($a),
        $authorities,
    )))));
}

it('binds positional arguments to the rule parameter names', function () {
    $rule = new SecurityMethodDescriptor('App\\S', 'm', 'permitAll()', ['ids', 'reason']);

    expect(evaluatorWith(new RecordingAuthenticationEvents)->bind($rule, [[1, 2], 'why']))->toBe(['ids' => [1, 2], 'reason' => 'why']);
});

it('refuses the pre rule with a 401 for anonymous and a 403 plus a denial event when authenticated', function () {
    $events = new RecordingAuthenticationEvents;
    $rule = new SecurityMethodDescriptor('App\\S', 'totals', "hasRole('ADMIN')", []);

    expect(fn () => evaluatorWith($events)->before($rule, []))->toThrow(AuthenticationException::class)
        ->and($events->denials())->toBe([]);

    signInAs('ada', 'ROLE_USER');

    expect(fn () => evaluatorWith($events)->before($rule, []))->toThrow(AuthorizationException::class)
        ->and($events->denials())->toHaveCount(1)
        ->and($events->denials()[0]->subject)->toBe('App\\S::totals')
        ->and($events->denials()[0]->expression)->toBe("hasRole('ADMIN')");
});

it('evaluates PostAuthorize with the return object bound, wording the refusal with the rule\'s own code', function () {
    $rule = new SecurityMethodDescriptor('App\\S', 'find', 'permitAll()', ['id'], postExpression: "hasPermission(#returnObject, 'READ')", postCode: 'REPORT_NOT_YOURS', postMessage: 'That report belongs to someone else.');
    signInAs('ada');
    $evaluator = evaluatorWith(new RecordingAuthenticationEvents);

    expect($evaluator->after($rule, ['id' => 2], new Report(2, 'ada')))->toEqual(new Report(2, 'ada'));

    try {
        $evaluator->after($rule, ['id' => 1], new Report(1, 'bob'));
        throw new LogicException('not refused');
    } catch (AuthorizationException $e) {
        expect($e->errorCode())->toBe('REPORT_NOT_YOURS')
            ->and($e->getMessage())->toBe('That report belongs to someone else.');
    }
});

it('narrows an array, a list and a Collection through PostFilter, and the named argument through PreFilter', function () {
    signInAs('ada');
    $evaluator = evaluatorWith(new RecordingAuthenticationEvents);
    $reports = [new Report(1, 'bob'), new Report(2, 'ada'), new Report(3, 'bob'), new Report(4, 'ada')];

    $postFilter = new SecurityMethodDescriptor('App\\S', 'all', 'permitAll()', [], postFilter: "hasPermission(#filterObject, 'READ')");

    $collection = $evaluator->after($postFilter, [], new Collection($reports));

    expect($evaluator->after($postFilter, [], $reports))->toEqual([new Report(2, 'ada'), new Report(4, 'ada')])
        ->and($evaluator->after($postFilter, [], ['a' => $reports[0], 'b' => $reports[1]]))->toEqual(['b' => $reports[1]])
        ->and($collection)->toBeInstanceOf(Collection::class)
        ->and($collection instanceof Collection ? $collection->values()->all() : null)->toEqual([new Report(2, 'ada'), new Report(4, 'ada')]);

    $preFilter = new SecurityMethodDescriptor('App\\S', 'purge', 'permitAll()', ['ids', 'reason'], preFilter: "hasPermission(#filterObject, 'WRITE')", preFilterTarget: 'ids');

    expect($evaluator->before($preFilter, ['ids' => [1, 2, 3, 4], 'reason' => 'stale']))->toBe(['ids' => [2, 4], 'reason' => 'stale']);
});

it('fails closed when a filter rule meets a value that is not iterable', function () {
    signInAs('ada');
    $rule = new SecurityMethodDescriptor('App\\S', 'one', 'permitAll()', [], postFilter: "hasPermission(#filterObject, 'READ')");

    expect(fn () => evaluatorWith(new RecordingAuthenticationEvents)->after($rule, [], new Report(2, 'ada')))->toThrow(AuthorizationException::class);
});

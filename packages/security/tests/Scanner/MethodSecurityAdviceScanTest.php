<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\Access\Method\SecurityMethodDescriptor;
use Firefly\Security\Scanner\MethodSecurityScanner;
use Firefly\Security\Tests\Fixtures\Advice\ArchiveHandler;
use Firefly\Security\Tests\Fixtures\Advice\OwnedReportService;
use Firefly\Security\Tests\Fixtures\Advice\ReportController;
use Firefly\Security\Tests\Fixtures\Advice\ReportService;

/** @return array<string,string> */
function advicePsr4(): array
{
    return ['Firefly\\Security\\Tests\\Fixtures\\Advice\\' => dirname(__DIR__).'/Fixtures/Advice'];
}

/** @return array<string,string> */
function malformedPsr4(string $directory): array
{
    return ['Firefly\\Security\\Tests\\MalformedFixtures\\'.$directory.'\\' => dirname(__DIR__).'/MalformedFixtures/'.$directory];
}

/** @return array<string, SecurityMethodDescriptor> keyed by Class::method */
function adviceRules(): array
{
    $rules = [];
    foreach ((new MethodSecurityScanner)->scan(advicePsr4()) as $rule) {
        $rules[$rule->key()] = $rule;
    }

    return $rules;
}

it('compiles post, pre-filter and post-filter rules beside the pre rule', function () {
    $rules = adviceRules();

    $find = $rules[ReportService::class.'::find'];
    $all = $rules[ReportService::class.'::all'];
    $purge = $rules[ReportService::class.'::purge'];

    expect($find->expression)->toBe('permitAll()')
        ->and($find->postExpression)->toBe("hasPermission(#returnObject, 'READ')")
        ->and($find->postCode)->toBe('REPORT_NOT_YOURS')
        ->and($find->postMessage)->toBe('That report belongs to someone else.')
        ->and($all->postFilter)->toBe("hasPermission(#filterObject, 'READ')")
        ->and($purge->preFilter)->toBe("hasPermission(#filterObject, 'WRITE')")
        ->and($purge->preFilterTarget)->toBe('ids')
        ->and($purge->params)->toBe(['ids', 'reason'])
        ->and($rules)->not->toHaveKey(ReportService::class.'::unguarded')
        ->and(SecurityMethodDescriptor::fromArray($find->toArray()))->toEqual($find);
});

it('infers the #[PreFilter] target as the sole array/iterable parameter, wherever it sits in the signature', function () {
    $archive = adviceRules()[ReportService::class.'::archive'];

    expect($archive->params)->toBe(['reason', 'ids'])
        ->and($archive->preFilter)->toBe("hasPermission(#filterObject, 'WRITE')")
        ->and($archive->preFilterTarget)->toBe('ids');
});

it('applies a class-level #[PostAuthorize] to every method and lets a method-level one replace it whole', function () {
    $rules = adviceRules();

    $find = $rules[OwnedReportService::class.'::find'];
    $latest = $rules[OwnedReportService::class.'::latest'];

    expect($find->expression)->toBe('permitAll()')
        ->and($find->postExpression)->toBe("hasPermission(#returnObject, 'READ')")
        ->and($find->postCode)->toBe('REPORT_NOT_YOURS')
        ->and($find->postMessage)->toBe('That report belongs to someone else.')
        // Replaced, not merged: the class rule's code and sentence do not leak onto the method's own rule.
        ->and($latest->postExpression)->toBe('permitAll()')
        ->and($latest->postCode)->toBeNull()
        ->and($latest->postMessage)->toBeNull();
});

it('plans a proxy for the stereotyped services and leaves the controller and the pre-only handler to their seams', function () {
    $advice = (new MethodSecurityScanner)->scanProxyAdvice(advicePsr4());

    expect(array_keys($advice))->toBe([OwnedReportService::class, ReportService::class])
        ->and(array_keys($advice[ReportService::class]))->toBe(['all', 'archive', 'find', 'purge', 'totals'])
        ->and(array_keys($advice[OwnedReportService::class]))->toBe(['find', 'latest'])
        ->and($advice)->not->toHaveKey(ReportController::class)
        ->and($advice)->not->toHaveKey(ArchiveHandler::class);
});

it('refuses a final bean whose rules nothing but a proxy could enforce', function () {
    expect(fn () => (new MethodSecurityScanner)->scanProxyAdvice(malformedPsr4('FinalService')))
        ->toThrow(ConfigurationException::class, 'SealedService');
});

it('refuses a #[PreFilter] on a controller action, which the dispatcher could evaluate but never apply', function () {
    expect(fn () => (new MethodSecurityScanner)->scanProxyAdvice(malformedPsr4('ControllerPreFilter')))
        ->toThrow(ConfigurationException::class, 'FilteringController::purge');
});

it('refuses a #[PostAuthorize] on a class with no stereotype, which no proxy and no dispatch seam would enforce', function () {
    expect(fn () => (new MethodSecurityScanner)->scanProxyAdvice(malformedPsr4('PlainPostAuthorize')))
        ->toThrow(ConfigurationException::class, 'UnstereotypedReports::find');
});

it('refuses a #[PreFilter] whose filterTarget names a parameter the method does not declare', function () {
    expect(fn () => (new MethodSecurityScanner)->scan(malformedPsr4('PreFilterUnknownTarget')))
        ->toThrow(ConfigurationException::class, 'MisnamedTargetService::purge');
});

it('refuses a #[PreFilter] with no filterTarget on a method declaring two iterable parameters', function () {
    expect(fn () => (new MethodSecurityScanner)->scan(malformedPsr4('PreFilterAmbiguousTarget')))
        ->toThrow(ConfigurationException::class, 'TwoListsService::merge');
});

<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\Access\Method\SecurityMethodDescriptor;
use Firefly\Security\Scanner\MethodSecurityScanner;
use Firefly\Security\Tests\Fixtures\Advice\ArchiveHandler;
use Firefly\Security\Tests\Fixtures\Advice\ReportController;
use Firefly\Security\Tests\Fixtures\Advice\ReportService;

/** @return array<string,string> */
function advicePsr4(): array
{
    return ['Firefly\\Security\\Tests\\Fixtures\\Advice\\' => dirname(__DIR__).'/Fixtures/Advice'];
}

it('compiles post, pre-filter and post-filter rules beside the pre rule', function () {
    $rules = [];
    foreach ((new MethodSecurityScanner)->scan(advicePsr4()) as $rule) {
        $rules[$rule->key()] = $rule;
    }

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

it('plans a proxy for the stereotyped service and leaves the controller and the pre-only handler to their seams', function () {
    $advice = (new MethodSecurityScanner)->scanProxyAdvice(advicePsr4());

    expect(array_keys($advice))->toBe([ReportService::class])
        ->and(array_keys($advice[ReportService::class]))->toBe(['all', 'find', 'purge', 'totals'])
        ->and($advice)->not->toHaveKey(ReportController::class)
        ->and($advice)->not->toHaveKey(ArchiveHandler::class);
});

it('refuses a final bean whose rules nothing but a proxy could enforce', function () {
    expect(fn () => (new MethodSecurityScanner)->scanProxyAdvice(['Firefly\\Security\\Tests\\MalformedFixtures\\FinalService\\' => dirname(__DIR__).'/MalformedFixtures/FinalService']))
        ->toThrow(ConfigurationException::class, 'SealedService');
});

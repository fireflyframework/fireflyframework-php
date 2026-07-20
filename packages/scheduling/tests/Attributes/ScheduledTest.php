<?php

declare(strict_types=1);

use Firefly\Scheduling\Attributes\Scheduled;

it('exposes its trigger and lock metadata', function () {
    $scheduled = new Scheduled(cron: '0 3 * * *', zone: 'UTC', lock: true, lockTtl: '30s');

    expect($scheduled->cron)->toBe('0 3 * * *')
        ->and($scheduled->fixedRate)->toBeNull()
        ->and($scheduled->zone)->toBe('UTC')
        ->and($scheduled->lock)->toBeTrue()
        ->and($scheduled->lockTtl)->toBe('30s');
});

it('accepts a fixedRate-only or fixedDelay-only trigger', function () {
    expect((new Scheduled(fixedRate: '5m'))->fixedRate)->toBe('5m')
        ->and((new Scheduled(fixedDelay: '90s'))->fixedDelay)->toBe('90s');
});

it('requires EXACTLY ONE of cron/fixedRate/fixedDelay', function (Closure $construct) {
    expect($construct)->toThrow(InvalidArgumentException::class);
})->with([
    'none' => [fn () => new Scheduled],
    'two' => [fn () => new Scheduled(cron: '* * * * *', fixedRate: '5s')],
    'all three' => [fn () => new Scheduled(cron: '* * * * *', fixedRate: '5s', fixedDelay: '5s')],
]);

it('targets methods only', function () {
    $attribute = (new ReflectionClass(Scheduled::class))->getAttributes(Attribute::class)[0]->newInstance();

    expect($attribute->flags)->toBe(Attribute::TARGET_METHOD);
});

<?php

declare(strict_types=1);

use Firefly\Testing\Slice\DataSliceTestCase;
use Firefly\Testing\Tests\Fixtures\Slice\DataBean;
use Firefly\Testing\Tests\Fixtures\SliceData\PricedBean;
use Firefly\Testing\Tests\Fixtures\SliceData\PricingPort;

uses(DataSliceTestCase::class);

it('boots ONLY the sliced data beans and resolves them', function () {
    /** @var DataSliceTestCase $this */
    $context = $this->dataSlice(
        scan: [
            'Firefly\\Testing\\Tests\\Fixtures\\Slice\\' => dirname(__DIR__).'/Fixtures/Slice',
            'Firefly\\Testing\\Tests\\Fixtures\\SliceData\\' => dirname(__DIR__).'/Fixtures/SliceData',
        ],
        overrides: [
            PricingPort::class => new class implements PricingPort
            {
                public function price(): int
                {
                    return 42;
                }
            },
        ],
    );

    /** @var DataBean $dataBean */
    $dataBean = $context->get(DataBean::class);

    expect($dataBean)->toBeInstanceOf(DataBean::class)
        ->and($dataBean->label())->toBe('data-bean');

    // plan-review C2 fail-fast proof: PricedBean's constructor requires PricingPort, which has no
    // binding anywhere except this test's $overrides. If dataSlice() ever regresses to recording
    // $sliceScan/$sliceOverrides WITHOUT rebooting (refreshApplication()), the override never reaches
    // the container and this line throws instead of silently passing — unlike the DataBean assertion
    // above, which plain reflection can satisfy with zero scoping.
    /** @var PricedBean $pricedBean */
    $pricedBean = $context->get(PricedBean::class);

    expect($pricedBean->price())->toBe(42);
});

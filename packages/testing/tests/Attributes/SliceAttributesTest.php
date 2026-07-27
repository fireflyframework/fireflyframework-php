<?php

declare(strict_types=1);

namespace Firefly\Testing\Tests\Attributes;

use Firefly\Testing\Attributes\WebSlice;
use Firefly\Testing\Slice\WebSliceTestCase;
use Firefly\Testing\Tests\Fixtures\Slice\SliceController;

/**
 * The ONE class-style test in the monorepo — deliberately, to prove the #[WebSlice] attribute path works
 * under this repo's Pest runner (probed green during planning). Every other test stays a Pest closure.
 */
#[WebSlice(scan: ['Firefly\\Testing\\Tests\\Fixtures\\Slice\\' => __DIR__.'/../Fixtures/Slice'])]
final class SliceAttributesTest extends WebSliceTestCase
{
    public function test_web_slice_attribute_boots_the_sliced_route(): void
    {
        $response = $this->get('/slice/ping');

        $this->assertSame(200, $response->status());
        $this->assertTrue($response->json('pong'));
        $this->assertInstanceOf(SliceController::class, $this->fireflyContext()->get(SliceController::class));
    }
}

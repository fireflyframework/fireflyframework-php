<?php

declare(strict_types=1);

use Firefly\Cli\Tests\Support\SkeletonApp;
use Firefly\Cli\Tests\Support\SkeletonScannedBootTestCase;

/**
 * The shipped skeleton on the UNCACHED boot path — what a developer gets from `artisan serve` after editing
 * app/ and before re-running `firefly:cache`.
 *
 * SkeletonExampleTest covers the compiled path. This file exists because the two are different code: one
 * requires a pure-array manifest, the other reflects at boot. The list-of-DTOs binding is the reason to care
 * — its element type is recovered from a constructor DOCBLOCK, and a docblock is exactly the kind of input
 * that can be present for the compiler and absent at runtime (opcache's `opcache.save_comments=0` strips
 * doc comments outright, which is a real production configuration).
 */
uses(SkeletonScannedBootTestCase::class);

SkeletonApp::register();

it('serves the sample resource with no compiled manifest at all', function (): void {
    /** @var SkeletonScannedBootTestCase $this */
    $created = $this->postJson('/orders', SkeletonApp::orderBody());

    $created->assertStatus(201)
        ->assertJsonPath('shipTo.postcode', 'W1A 1AA')
        // 22.25 is only reachable if every element of `lines` became a real OrderLinePayload: the domain
        // computes the total from OrderLine objects mapped out of them.
        ->assertJsonPath('total', 22.25);

    $id = $created->json('id');
    if (! is_int($id)) {
        throw new RuntimeException('the created order came back without an integer id.');
    }

    $this->getJson('/orders/'.$id)->assertOk()->assertJsonPath('id', $id);
    $this->deleteJson('/orders/'.$id)->assertNoContent();
    $this->getJson('/orders/'.$id)->assertStatus(404);
});

it('still validates the nested payload when the constraints are scanned rather than loaded', function (): void {
    /** @var SkeletonScannedBootTestCase $this */
    $response = $this->postJson('/orders', SkeletonApp::orderBody([
        'shipTo' => ['street' => '12 Analytical Way', 'city' => 'London', 'postcode' => 'W1A 1AA', 'country' => 'XX'],
    ]));

    $response->assertStatus(422);
    expect(array_column((array) $response->json('errors'), 'field'))->toContain('shipTo.country');
});

it('serves the minimal greeting slice on the uncached path too', function (): void {
    /** @var SkeletonScannedBootTestCase $this */
    // #[ConfigProperties] DTOs are only BOUND on the cached path; on this one GreetingProperties is resolved
    // by plain autowiring, which lands on its constructor defaults. The skeleton ships no `greeting.*`
    // configuration, so both paths must agree — and this asserts they do.
    $this->getJson('/greetings/Ada')
        ->assertOk()
        ->assertExactJson(['message' => 'Hello, Ada!']);
});

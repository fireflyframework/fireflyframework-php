<?php

declare(strict_types=1);

use Firefly\Web\Tests\Support\UncachedBootTestCase;

uses(UncachedBootTestCase::class);

/**
 * The END-TO-END half of the nested-body fix, on the boot a real application takes (no compiled artifact,
 * WebServiceProvider resolving its own manifests). /transfers takes a MoneyTransferRequest whose
 * $beneficiary is another DTO and whose $lines is a list of DTOs.
 *
 * Two halves had to meet for this to work. ArgumentResolver gained a hydration engine driven by a compiled
 * `dtos` shape table, and RouteScanner had to learn to EMIT that table — until it did, every production
 * route fell through to the resolver's "plan cannot say" path, so the engine was real, tested and
 * unreachable. These cases drive the scanner's own output, which is what proves the halves agree.
 *
 * Before either half existed, a perfectly valid nested request died as a TypeError rendered as HTTP 500
 * whose `detail` quoted this repository's absolute path back to whoever sent it.
 */
it('hydrates a nested request body that used to raise a TypeError 500', function () {
    /** @var UncachedBootTestCase $this */
    $this->postJson('/transfers', [
        'amount' => 250,
        'beneficiary' => [
            'street' => 'Calle Mayor 1',
            'postcode' => '28013',
            'geo' => ['lat' => 40.4168, 'lon' => -3.7038],
        ],
        'lines' => [['reference' => 'INV-1', 'cents' => 100]],
    ])
        ->assertStatus(201)
        ->assertExactJson(['amount' => 250, 'postcode' => '28013', 'lines' => 1]);
});

// The other side of the contract: a DTO the plan genuinely cannot describe (an interface-typed property)
// still reaches the constructor untouched, and that rejection is a CLIENT error, not a 500.
it('answers a body it cannot possibly bind with a clean 400', function () {
    /** @var UncachedBootTestCase $this */
    $this->postJson('/unbindable', ['name' => 'Ada', 'counter' => ['items' => 2]])
        ->assertStatus(400)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'UNBINDABLE_BODY')
        ->assertJsonPath('category', 'validation');
});

it('never quotes a filesystem path or an internal type back to the client', function () {
    /** @var UncachedBootTestCase $this */
    $body = (string) $this->postJson('/unbindable', ['name' => 'Ada', 'counter' => ['items' => 2]])->getContent();

    // The pre-fix payload read: "...must be of type Firefly\Web\Tests\Fixtures\AddressPayload, array given,
    // called in /Users/<someone>/.../packages/web/src/Dispatch/ArgumentResolver.php on line 144".
    expect($body)->not->toContain(dirname(__DIR__, 4))
        ->and($body)->not->toContain('ArgumentResolver.php')
        ->and($body)->not->toContain('must be of type');
});

it('still validates the nested payload before it ever reaches hydration', function () {
    /** @var UncachedBootTestCase $this */
    // The #[Valid] cascade compiles `beneficiary.postcode` as a dot-key, so an empty nested postcode is a
    // 422 — it must not be overtaken by hydration succeeding.
    $this->postJson('/transfers', [
        'amount' => 250,
        'beneficiary' => ['street' => 'Calle Mayor 1', 'postcode' => ''],
    ])->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_ERROR');
});

<?php

declare(strict_types=1);

use App\Cards\CardDesk;
use Firefly\Tests\Support\TutorialTestCase;

uses(TutorialTestCase::class);

/**
 * docs/tutorial.md and docs/tutorial.es.md print tests/Fixtures/Tutorial/app/** verbatim (source: markers, held line
 * for line by DocsCodeIsRealTest), so what the reader types is what this test boots through the real web and
 * orchestration pipeline — and the curl transcripts the tutorial prints are these two requests.
 */
it('sends the card: reserve, charge and send run in order', function () {
    /** @var TutorialTestCase $this */
    $this->postJson('/cards/Ada')
        ->assertStatus(201)
        ->assertJsonPath('status', 'COMPLETED');

    expect($this->app()->make(CardDesk::class)->journal)
        ->toBe(['reserve card-1', 'charge payment-1 300', 'send card-1 to Ada']);
});

it('undoes the charge, then the reservation, when the card cannot be sent', function () {
    /** @var TutorialTestCase $this */
    $this->postJson('/cards/Nobody')
        ->assertStatus(409)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'CARD_NOT_SENT');

    expect($this->app()->make(CardDesk::class)->journal)
        ->toBe(['reserve card-1', 'charge payment-1 300', 'refund payment-1', 'release card-1']);
});

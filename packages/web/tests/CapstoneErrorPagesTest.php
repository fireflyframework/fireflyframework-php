<?php

declare(strict_types=1);

use Firefly\Web\Tests\Support\ErrorPagesCapstoneTestCase;

uses(ErrorPagesCapstoneTestCase::class);

it('answers a browser with the page and a client with the document, for one failure', function () {
    /** @var ErrorPagesCapstoneTestCase $this */
    $page = $this->get('/err/missing', ['Accept' => 'text/html,application/xhtml+xml']);
    $page->assertStatus(404)->assertHeader('Content-Type', 'text/html; charset=UTF-8');

    $document = $this->getJson('/err/missing');
    $document->assertStatus(404)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'ORDER_NOT_FOUND')
        ->assertJsonPath('detail', 'Order 42 does not exist.')
        ->assertJsonPath('instance', '/err/missing')
        ->assertJsonPath('type', 'https://api.example.test/problems/order-not-found');

    // The same words on both surfaces, which is the whole point of sharing ProblemMapper.
    expect((string) $page->baseResponse->getContent())->toContain('Order 42 does not exist.')
        ->toContain('ORDER_NOT_FOUND');
});

it('answers a bare curl with problem+json instead of Laravel\'s stock page', function () {
    /** @var ErrorPagesCapstoneTestCase $this */
    // THE BLOCKER: `Accept: */*` is what curl and fetch() send by default, and a route-level throwable that
    // is not a FireflyException used to fall all the way through to Laravel's HTML handler.
    $this->get('/err/nothing-here', ['Accept' => '*/*'])
        ->assertStatus(404)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'RESOURCE_NOT_FOUND')
        ->assertJsonPath('instance', '/err/nothing-here');

    // Symfony supplies a browser Accept by default; null removes it from the real request.
    $this->call('GET', '/err/nothing-here', server: ['HTTP_ACCEPT' => null])
        ->assertStatus(404)
        ->assertHeader('Content-Type', 'application/problem+json');
});

it('bounds the debug page it renders for a hundred-frame failure', function () {
    /** @var ErrorPagesCapstoneTestCase $this */
    $html = (string) $this->get('/err/wrecked', ['Accept' => 'text/html'])->baseResponse->getContent();

    $rows = substr_count($html, '<li class="own">') + substr_count($html, '<li class="vendor">');

    expect($rows)->toBeLessThanOrEqual(25)
        ->and($rows)->toBeGreaterThan(0)
        // Shortened, split, and on one line: no row carries the absolute path that made this page 10,108
        // pixels tall, and no frame's file name is inside a directory span.
        ->and($html)->not->toContain('<span class="dir">/')
        ->toContain('<details class="deps">')
        ->toContain('The fixture failed on purpose.')
        ->toContain('the inner cause');
});

it('renders its own page for a failure with no reference of its own, and never throws doing it', function () {
    /** @var ErrorPagesCapstoneTestCase $this */
    $response = $this->get('/err/wrecked', ['Accept' => 'text/html']);

    $response->assertStatus(500)->assertHeader('X-Correlation-Id');

    expect((string) $response->baseResponse->getContent())->toContain('<dt>Reference</dt><dd>');
});

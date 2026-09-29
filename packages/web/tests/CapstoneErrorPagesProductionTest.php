<?php

declare(strict_types=1);

use Firefly\Web\Tests\Support\ProductionErrorPagesCapstoneTestCase;

uses(ProductionErrorPagesCapstoneTestCase::class);

it('shows a stranger the authored sentence, the code, the reference and a way out — and nothing else', function () {
    /** @var ProductionErrorPagesCapstoneTestCase $this */
    $html = (string) $this->get('/err/missing', ['Accept' => 'text/html'])->baseResponse->getContent();

    expect($html)->toContain('Order 42 does not exist.')
        ->toContain('ORDER_NOT_FOUND')
        ->toContain('<dt>Reference</dt><dd>')
        ->toContain('<a class="act primary" href="/">Go home</a>')
        // Nothing internal, and not the page's own advice about how to turn the trace on.
        ->not->toContain('ResourceNotFoundException')
        ->not->toContain('Stack trace')
        ->not->toContain('APP_DEBUG')
        ->not->toContain('OrderService.php');
});

it('withholds a generic failure\'s cause while still handing over an id to quote', function () {
    /** @var ProductionErrorPagesCapstoneTestCase $this */
    $response = $this->get('/err/wrecked', ['Accept' => 'text/html']);
    $html = (string) $response->baseResponse->getContent();

    $response->assertStatus(500);

    expect($html)->toContain('Something went wrong on our side.')
        ->toContain('quote the reference below if you report it')
        ->toContain('<a class="act primary" href="/err/wrecked">Try again</a>')
        ->not->toContain('The fixture failed on purpose.')
        ->not->toContain('the inner cause')
        ->not->toContain('LogicException')
        ->not->toContain('Caused by');
});

it('keeps the document opaque for a client too, with the same status and code', function () {
    /** @var ProductionErrorPagesCapstoneTestCase $this */
    $this->getJson('/err/wrecked')
        ->assertStatus(500)
        ->assertJsonPath('code', 'INTERNAL_ERROR')
        ->assertJsonPath('type', 'https://api.example.test/problems/internal-error')
        ->assertJsonMissing(['detail' => 'The fixture failed on purpose.']);
});

it('names the verbs on both surfaces for a 405 the router raised', function () {
    /** @var ProductionErrorPagesCapstoneTestCase $this */
    // Only the router produces a real Allow header, which is why this is a capstone and not a unit test.
    $document = $this->getJson('/err/submit');
    $document->assertStatus(405)
        ->assertJsonPath('code', 'METHOD_NOT_ALLOWED')
        ->assertJsonPath('allowed', ['POST'])
        ->assertJsonPath('detail', 'This address only accepts POST.')
        ->assertHeader('Allow');

    $page = $this->get('/err/submit', ['Accept' => 'text/html']);
    $page->assertStatus(405);

    expect((string) $page->baseResponse->getContent())
        ->toContain('That address does not accept a GET request. It accepts POST.');
});

it('keeps an author\'s abort() sentence and replaces the router\'s, on the page as on the wire', function () {
    /** @var ProductionErrorPagesCapstoneTestCase $this */
    $authored = (string) $this->get('/err/aborted', ['Accept' => 'text/html'])->baseResponse->getContent();
    $router = (string) $this->get('/err/no-such-route', ['Accept' => 'text/html'])->baseResponse->getContent();

    expect($authored)->toContain('No such tenant.')
        ->and($router)->toContain('There is nothing at this address.')
        ->not->toContain('could not be found');
});

it('offers sign-in on a 401 and nothing of the sort on a 403', function () {
    /** @var ProductionErrorPagesCapstoneTestCase $this */
    $refused = (string) $this->get('/err/refused', ['Accept' => 'text/html'])->baseResponse->getContent();
    $denied = (string) $this->get('/err/denied', ['Accept' => 'text/html'])->baseResponse->getContent();

    expect($refused)->toContain('<a class="act primary" href="/login">Sign in</a>')
        ->toContain('Authentication is required to access this resource.')
        ->and($denied)->toContain('Access is denied.')
        ->toContain('<a class="act primary" href="/">Go home</a>')
        ->not->toContain('Sign in');
});

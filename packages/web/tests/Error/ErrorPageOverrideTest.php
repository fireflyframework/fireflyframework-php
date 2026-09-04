<?php

declare(strict_types=1);

use Firefly\Testing\FireflyTestCase;
use Firefly\Web\Error\ErrorPageRenderer;
use Firefly\Web\Error\ErrorPageSettings;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * An application overriding the error page with its own view.
 *
 * Tested against the REAL view factory and real Blade files on disk rather than a mock, because the two
 * things worth asserting are both properties of real rendering: that the override receives the same
 * ErrorReport the built-in page does — and is therefore bound by the same `trace` gate — and that a view
 * which THROWS falls back instead of propagating. A mock can be told to throw; only a real compile can
 * throw the way a drifted template actually does.
 */
uses(FireflyTestCase::class);

beforeEach(function () {
    View::addNamespace('firefly-web-tests', __DIR__.'/../Fixtures/views');
});

$renderer = static function (string $status, string $view, bool $trace = false): ErrorPageRenderer {
    return new ErrorPageRenderer(
        new ErrorPageSettings(trace: $trace, views: [$status => $view]),
        '',
        app(ViewFactory::class),
    );
};

$render = static fn (ErrorPageRenderer $renderer): string => (string) $renderer->render(
    new NotFoundHttpException,
    Request::create('/nope', 'GET', server: ['HTTP_ACCEPT' => 'text/html']),
)->getContent();

it('renders the application\'s own view for a status it names', function () use ($renderer, $render) {
    $html = $render($renderer('404', 'firefly-web-tests::custom-error'));

    expect(trim($html))->toBe('OUR OWN PAGE · 404 · RESOURCE_NOT_FOUND');
});

it('falls back to a default entry for a status with no specific view', function () use ($renderer, $render) {
    $html = $render($renderer('default', 'firefly-web-tests::custom-error'));

    expect($html)->toContain('OUR OWN PAGE · 404');
});

it('holds an override to the same trace gate as the built-in page', function () use ($renderer, $render) {
    // The override prints `$error->message` only when the report says it is detailed. With `trace` off the
    // report never gathered a message, so a custom view cannot print one however it is written — the gate
    // is on the data, not on the template.
    expect($render($renderer('404', 'firefly-web-tests::custom-error', trace: false)))
        ->not->toContain('Not Found ·  ')
        ->and(trim($render($renderer('404', 'firefly-web-tests::custom-error', trace: false))))
        ->toEndWith('RESOURCE_NOT_FOUND');

    expect($render($renderer('404', 'firefly-web-tests::custom-error', trace: true)))
        ->toContain('RESOURCE_NOT_FOUND ·');
});

it('falls back to the built-in page when the override throws', function () use ($renderer, $render) {
    // This runs while the application is already failing, and an override is application code — a renamed
    // layout, a component querying the database that is down. Letting it propagate would replace a
    // diagnostic page with a white screen at exactly the moment someone needs to read one.
    $html = $render($renderer('default', 'firefly-web-tests::broken-error'));

    expect($html)->toContain('RESOURCE_NOT_FOUND')
        ->toContain('<!DOCTYPE html>');
});

it('uses the built-in page when the named view does not exist', function () use ($renderer, $render) {
    expect($render($renderer('404', 'firefly-web-tests::no-such-view')))->toContain('<!DOCTYPE html>');
});

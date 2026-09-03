<?php

declare(strict_types=1);

use Firefly\Web\Attributes\Controller;
use Firefly\Web\Attributes\RestController;
use Firefly\Web\Dispatch\ResponseFactory;
use Firefly\Web\Http\JsonMessageConverter;
use Firefly\Web\Http\MessageConverterRegistry;
use Firefly\Web\Route\RouteDescriptor;
use Firefly\Web\Tests\Fixtures\View\RecordingViewFactory;
use Firefly\Web\View\ModelAndView;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;

/**
 * A returned Blade View used to serialise to `{}` with HTTP 200 application/json — a View exposes no public
 * properties, so json_encode() produced an empty object and the framework could not serve HTML at all.
 */
function htmlResponseFactory(?RecordingViewFactory $views = null): ResponseFactory
{
    return new ResponseFactory(new MessageConverterRegistry([new JsonMessageConverter]), $views);
}

function htmlDescriptor(int $status = 200): RouteDescriptor
{
    return new RouteDescriptor('GET', '/page', 'Fixture', 'index', $status, null, []);
}

it('renders a Renderable as text/html instead of encoding it to {}', function () {
    $renderable = new class implements Renderable
    {
        public function render(): string
        {
            return '<h1>Hello</h1>';
        }
    };

    $response = htmlResponseFactory()->make($renderable, htmlDescriptor(), Request::create('/page'));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Content-Type'))->toBe('text/html; charset=UTF-8')
        ->and($response->getContent())->toBe('<h1>Hello</h1>');
});

it('renders an Htmlable as text/html', function () {
    $htmlable = new class implements Htmlable
    {
        public function toHtml(): string
        {
            return '<p>markup</p>';
        }
    };

    $response = htmlResponseFactory()->make($htmlable, htmlDescriptor(), Request::create('/page'));

    expect($response->headers->get('Content-Type'))->toBe('text/html; charset=UTF-8')
        ->and($response->getContent())->toBe('<p>markup</p>');
});

it('resolves a ModelAndView through the view factory, honouring status and headers', function () {
    $mav = ModelAndView::of('welcome', ['name' => 'Ada'])->withStatus(201)->withHeader('X-Page', 'welcome');
    $response = htmlResponseFactory(new RecordingViewFactory)->make($mav, htmlDescriptor(), Request::create('/page'));

    expect($response->getStatusCode())->toBe(201)
        ->and($response->getContent())->toBe('welcome:name')
        ->and($response->headers->get('X-Page'))->toBe('welcome');
});

it('fails loud rather than rendering nothing when no view factory is bound', function () {
    $mav = ModelAndView::of('welcome');

    expect(static fn () => htmlResponseFactory()->make($mav, htmlDescriptor(), Request::create('/page')))
        ->toThrow(LogicException::class);
});

it('still negotiates arrays and scalars to JSON — the HTML branch is additive', function () {
    $response = htmlResponseFactory()->make(['ok' => true], htmlDescriptor(), Request::create('/page'));

    expect($response->headers->get('Content-Type'))->toBe('application/json')
        ->and($response->getContent())->toBe('{"ok":true}');
});

// RouteScanner discovers controllers with an IS_INSTANCEOF filter on #[RestController], so #[Controller]
// extending it is found by the existing scan with no scanner change.
it('makes #[Controller] discoverable by the existing #[RestController] scan', function () {
    expect(is_subclass_of(Controller::class, RestController::class))->toBeTrue();
});

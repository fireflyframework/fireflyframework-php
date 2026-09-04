<?php

declare(strict_types=1);

namespace Firefly\Web\Dispatch;

use Firefly\Web\Http\MessageConverterRegistry;
use Firefly\Web\Route\RouteDescriptor;
use Firefly\Web\View\ModelAndView;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Content-negotiates a controller return. An already-built Response (Symfony OR Illuminate — incl.
 * JsonResponse) or a Responsable passes through untouched; a View / ModelAndView / Htmlable / Renderable is
 * rendered as text/html; any other value (array/JsonSerializable/Arrayable/scalar) is written by the
 * MessageConverter chosen from the request Accept header and wrapped in a Response carrying $status if given,
 * else the descriptor's default status. Return type is the Symfony HttpFoundation Response supertype so a
 * JsonResponse passthrough type-checks.
 *
 * HTML RENDERING. The view branch did not exist before. A Blade View is neither a SymfonyResponse nor a
 * Responsable, so it fell through to the converter chain and was json_encode()d — and because a View exposes
 * no public properties, every returned view became the body `{}` with HTTP 200 and Content-Type
 * application/json. Silently. That made server-rendered HTML impossible in a framework that otherwise
 * advertises itself as a home for "any kind of project", and it is why there was no welcome page: there was
 * no way to render one.
 */
final class ResponseFactory
{
    /**
     * @param  ViewFactory|null  $views  the application's view factory, used only to resolve a ModelAndView's
     *                                   view NAME. Null when illuminate/view is not installed (a JSON-only
     *                                   deployment, or a unit test) — returning a ModelAndView then fails loud
     *                                   rather than silently rendering nothing.
     */
    public function __construct(
        private readonly MessageConverterRegistry $converters,
        private readonly ?ViewFactory $views = null,
    ) {}

    /**
     * @param  int|null  $status  status override for the negotiated body (e.g. a matched #[ExceptionHandler]
     *                            renders at the exception's httpStatus()); null = the descriptor's status.
     */
    public function make(mixed $result, RouteDescriptor $descriptor, Request $request, ?int $status = null): SymfonyResponse
    {
        if ($result instanceof SymfonyResponse) {
            return $result;
        }

        if ($result instanceof Responsable) {
            return $result->toResponse($request);
        }

        if ($result instanceof ModelAndView) {
            return $this->renderModelAndView($result, $status);
        }

        // A View is also Renderable, so it is covered by the Renderable arm; it is named explicitly for
        // clarity because it is by far the common case (`return view('welcome', [...])`).
        if ($result instanceof View || $result instanceof Renderable) {
            return $this->html($result->render(), $status ?? $descriptor->status);
        }

        if ($result instanceof Htmlable) {
            return $this->html($result->toHtml(), $status ?? $descriptor->status);
        }

        $accept = (string) $request->header('Accept', 'application/json');
        $converter = $this->converters->findWriter($accept);
        $mediaType = $converter?->mediaTypes()[0] ?? 'application/json';
        $body = $converter !== null
            ? $converter->write($result, $mediaType)
            : (string) json_encode($result, JSON_THROW_ON_ERROR);

        return new Response($body, $status ?? $descriptor->status, ['Content-Type' => $mediaType]);
    }

    private function renderModelAndView(ModelAndView $result, ?int $status): SymfonyResponse
    {
        if ($this->views === null) {
            throw new \LogicException(
                "Cannot render the view [{$result->view}]: no view factory is bound. Install illuminate/view "
                .'(it ships with laravel/framework) or return an already-rendered response.'
            );
        }

        return $this->html(
            $this->views->make($result->view, $result->model)->render(),
            $status ?? $result->status,
            $result->headers,
        );
    }

    /**
     * @param  array<string,string>  $headers
     */
    private function html(string $body, int $status, array $headers = []): SymfonyResponse
    {
        return new Response($body, $status, ['Content-Type' => 'text/html; charset=UTF-8', ...$headers]);
    }
}

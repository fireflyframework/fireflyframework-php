<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ReturnFixture;

use Firefly\OpenApi\Attributes\ApiResponse;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;
use Firefly\Web\View\ModelAndView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Actions that hand ResponseFactory something other than data: a response they built themselves, a value
 * that builds its own, or markup.
 */
#[RestController]
#[RequestMapping('/raw')]
final class RawController
{
    #[GetMapping('/json')]
    public function json(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }

    /** @return BinaryFileResponse the stored manifest, as uploaded */
    #[GetMapping('/file')]
    public function file(): BinaryFileResponse
    {
        return new BinaryFileResponse(__FILE__);
    }

    #[GetMapping('/stream')]
    public function stream(): StreamedResponse
    {
        return new StreamedResponse(static function (): void {
            echo 'chunk';
        });
    }

    #[GetMapping('/redirect')]
    public function redirect(): RedirectResponse
    {
        return new RedirectResponse('/elsewhere');
    }

    #[GetMapping('/plain')]
    public function plain(): Response
    {
        return new Response('plain');
    }

    #[GetMapping('/receipt')]
    public function receipt(): Receipt
    {
        return new Receipt('R-1');
    }

    #[GetMapping('/page')]
    public function page(): ModelAndView
    {
        return ModelAndView::of('welcome');
    }

    #[GetMapping('/banner')]
    public function banner(): Banner
    {
        return new Banner('Closed on Sunday');
    }

    /**
     * A union of two responses: which one is sent, and with it the media type and the status, is decided at
     * runtime. The declared type is not a single named class, which is how this used to slip past the
     * rendered-response branch and be documented as an `anyOf` of the two classes' internals.
     */
    #[GetMapping('/either')]
    public function either(): JsonResponse|RedirectResponse
    {
        return $this->redirecting() ? new RedirectResponse('/elsewhere') : new JsonResponse(['ok' => true]);
    }

    /**
     * No declared return type at all, so the `@return` line is the only thing that names what is sent.
     *
     * @return RedirectResponse the page that replaced this one
     */
    #[GetMapping('/documented-redirect')]
    public function documentedRedirect()
    {
        return new RedirectResponse('/elsewhere');
    }

    /**
     * The third door into the schema factory: an author who names a response class in the attribute itself.
     * The declared return here is data, so nothing but the attribute can be what reaches the factory.
     *
     * @return array<string, mixed>
     */
    #[GetMapping('/attributed')]
    #[ApiResponse(status: 200, description: 'A body the action built itself.', type: JsonResponse::class)]
    public function attributed(): array
    {
        return ['ok' => true];
    }

    /** Private, so RouteScanner — which reads public methods only — never mistakes it for an action. */
    private function redirecting(): bool
    {
        return false;
    }
}

<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ReturnFixture;

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
}

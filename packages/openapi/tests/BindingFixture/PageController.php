<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\BindingFixture;

use Firefly\Web\Attributes\Controller;
use Firefly\Web\Attributes\GetMapping;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\HtmlString;

#[Controller]
final class PageController
{
    /** @return array{message: string} */
    #[GetMapping('/binding/page-data')]
    public function data(): array
    {
        return ['message' => 'data from a page controller'];
    }

    #[GetMapping('/binding/page-markup')]
    public function markup(): HtmlString
    {
        return new HtmlString('<h1>A page</h1>');
    }

    #[GetMapping('/binding/page-redirect')]
    public function redirect(): RedirectResponse
    {
        return new RedirectResponse('/binding/page-markup');
    }
}

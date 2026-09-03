<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\Fixture;

use Firefly\Web\Attributes\Controller;
use Firefly\Web\Attributes\GetMapping;
use Illuminate\Contracts\View\View;

/**
 * The HTML stereotype. #[Controller] extends #[RestController], so RouteScanner finds it by the same
 * IS_INSTANCEOF filter and it lands in the RouteManifest alongside every JSON route — which is exactly why
 * the generator has to be able to tell them apart.
 */
#[Controller]
final class WelcomePageController
{
    #[GetMapping('/welcome', name: 'welcome')]
    public function index(): View
    {
        /** @var View $view */
        $view = view('welcome');

        return $view;
    }
}

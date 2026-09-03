<?php

declare(strict_types=1);

use Firefly\OpenApi\OpenApiServiceProvider;
use Firefly\OpenApi\OpenApiWiringProvider;
use Firefly\OpenApi\Web\ViewerPage;
use Firefly\Validation\Constraint\ConstraintManifest;
use Firefly\Web\Route\RouteManifest;

it('lets an application #[Bean] of the same type win over the framework default', function () {
    $app = fireflyApplication(
        config: ['firefly' => [
            'openapi' => [],
            'scan' => ['paths' => ['Firefly\\OpenApi\\Tests\\Override\\' => __DIR__.'/Override']],
        ]],
        providers: [OpenApiServiceProvider::class, OpenApiWiringProvider::class],
        bindings: [
            RouteManifest::class => new RouteManifest([]),
            ConstraintManifest::class => new ConstraintManifest([]),
        ],
    );

    /** @var ViewerPage $page */
    $page = $app->make(ViewerPage::class);

    // The whole override contract: every collaborator is a #[Bean] behind #[ConditionalOnMissingBean], so
    // replacing one is a five-line #[Configuration] in the app and needs no fork of the package.
    expect($page->render('/openapi.json', 'builtin'))->toContain('Corporate Console');
});

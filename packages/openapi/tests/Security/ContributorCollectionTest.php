<?php

declare(strict_types=1);

use Firefly\OpenApi\OpenApiServiceProvider;
use Firefly\OpenApi\OpenApiWiringProvider;
use Firefly\OpenApi\Security\SecurityModel;
use Firefly\Validation\Constraint\ConstraintManifest;
use Firefly\Web\Route\RouteDescriptor;
use Firefly\Web\Route\RouteManifest;

/**
 * THE SEAM, EXERCISED THROUGH THE REAL CONTAINER — because the way it fails is invisible.
 *
 * SecurityModel is assembled from `Container::getAll()`, which resolves the tag `firefly.contract.<interface>`,
 * and ContainerRegistrar writes that tag while walking the SCANNED component manifest. A contributor
 * registered as a #[Bean] is therefore constructed and then dropped: no error, no failing assertion
 * anywhere, just a document quietly missing the schemes or requirements that package meant to add. A test
 * that built the model by hand would pass in both worlds, so this one boots the providers and asks the
 * container.
 */
function contributorSecurityModel(): SecurityModel
{
    $app = fireflyApplication(
        config: ['firefly' => [
            'openapi' => ['security' => ['enabled' => true]],
            'scan' => ['paths' => ['Firefly\\OpenApi\\Tests\\ContributorFixture\\' => dirname(__DIR__).'/ContributorFixture']],
        ]],
        providers: [OpenApiServiceProvider::class, OpenApiWiringProvider::class],
        bindings: [
            RouteManifest::class => new RouteManifest([]),
            ConstraintManifest::class => new ConstraintManifest([]),
        ],
    );

    /** @var SecurityModel $model */
    $model = $app->make(SecurityModel::class);

    return $model;
}

it('collects a #[Component] contributor through the container tag', function () {
    expect(contributorSecurityModel()->schemes())->toHaveKey('oauth2AuthorizationCode');
});

it('collects a contributor registered as a #[Bean] under the interface, instead of dropping it silently', function () {
    $route = new RouteDescriptor('GET', '/orders', 'App\\Http\\DemoController', 'show', 200, null, []);

    expect(contributorSecurityModel()->requirementsFor($route))->toBe([['oauth2AuthorizationCode' => ['orders.read']]]);
});

it('does not collect the same contributor twice when it is both tagged and bound', function () {
    // The scanned component is tagged AND bound under its interface, so both collection paths find it. It
    // must still appear once: a requirement contributor counted twice would double every entry it produces.
    $model = contributorSecurityModel();

    expect(array_keys($model->schemes()))->toBe(['oauth2AuthorizationCode']);
});

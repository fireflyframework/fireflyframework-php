<?php

declare(strict_types=1);

namespace Firefly\OpenApi;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\OpenApi\Generator\OpenApiGenerator;
use Firefly\OpenApi\Generator\OperationFactory;
use Firefly\OpenApi\Schema\ConstraintSchemaMapper;
use Firefly\OpenApi\Schema\DtoSchemaFactory;
use Firefly\OpenApi\Web\ViewerPage;
use Firefly\Validation\Constraint\ConstraintManifest;
use Firefly\Web\Route\RouteManifest;

/**
 * The package's bean source, in the shape ActuatorAutoConfiguration establishes: config-derived values and
 * collaborators as #[Bean] methods, each behind #[ConditionalOnMissingBean] so an application that declares
 * its own #[Bean] of the same type WINS and this one silently backs off. That is the entire override story,
 * and it is why the pipeline is broken into six beans rather than one god object: swapping the whole
 * generator is rarely what someone wants, whereas replacing just ConstraintSchemaMapper (to teach it a
 * house-specific ValidationRule) or just ViewerPage (to ship a corporate console) is exactly what they want,
 * and each is a five-line #[Bean] in the app's own #[Configuration].
 *
 * The master gate `firefly.openapi.enabled` is enforced in OpenApiRouteRegistrar, NOT here — see that class.
 * Beans with no routes in front of them do nothing at all, so gating them would only make the switch harder
 * to reason about while `firefly:openapi` (which wants the generator regardless of whether the HTTP surface
 * is exposed) still needed a way around it.
 *
 * RouteManifest and ConstraintManifest are injected rather than resolved: both are bound by
 * WebServiceProvider, which — because firefly/web is a hard composer dependency of this package — is always
 * auto-discovered alongside these providers, and whose register() has always completed before the
 * EagerSingletons phase resolves anything here. Binding a defensive empty fallback for either would be
 * actively harmful: this package's providers sort BEFORE firefly/web's alphabetically, so a bound()-guarded
 * empty manifest here would win the race and permanently blank out the real one.
 */
#[Configuration]
#[Order(1000)]
final class OpenApiAutoConfiguration
{
    #[Bean]
    #[ConditionalOnMissingBean(OpenApiProperties::class)]
    public function openApiProperties(Config $config): OpenApiProperties
    {
        return OpenApiProperties::fromConfig($config);
    }

    #[Bean]
    #[ConditionalOnMissingBean(ConstraintSchemaMapper::class)]
    public function constraintSchemaMapper(): ConstraintSchemaMapper
    {
        return new ConstraintSchemaMapper;
    }

    #[Bean]
    #[ConditionalOnMissingBean(DtoSchemaFactory::class)]
    public function dtoSchemaFactory(ConstraintManifest $constraints, ConstraintSchemaMapper $mapper): DtoSchemaFactory
    {
        return new DtoSchemaFactory($constraints, $mapper);
    }

    #[Bean]
    #[ConditionalOnMissingBean(OperationFactory::class)]
    public function operationFactory(DtoSchemaFactory $schemas): OperationFactory
    {
        return new OperationFactory($schemas);
    }

    #[Bean]
    #[ConditionalOnMissingBean(OpenApiGenerator::class)]
    public function openApiGenerator(RouteManifest $routes, OpenApiProperties $properties, OperationFactory $operations): OpenApiGenerator
    {
        return new OpenApiGenerator($routes, $properties, $operations);
    }

    #[Bean]
    #[ConditionalOnMissingBean(ViewerPage::class)]
    public function viewerPage(OpenApiProperties $properties): ViewerPage
    {
        return new ViewerPage($properties->title);
    }
}

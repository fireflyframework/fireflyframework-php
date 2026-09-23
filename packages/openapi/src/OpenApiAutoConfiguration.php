<?php

declare(strict_types=1);

namespace Firefly\OpenApi;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Container\Container as FireflyContainer;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\OpenApi\Generator\DocumentInfo;
use Firefly\OpenApi\Generator\OpenApiGenerator;
use Firefly\OpenApi\Generator\OperationFactory;
use Firefly\OpenApi\Schema\ConstraintSchemaMapper;
use Firefly\OpenApi\Schema\DtoSchemaFactory;
use Firefly\OpenApi\Security\ConfiguredSecurity;
use Firefly\OpenApi\Security\SecurityModel;
use Firefly\OpenApi\Security\SecurityRequirementContributor;
use Firefly\OpenApi\Security\SecuritySchemeContributor;
use Firefly\OpenApi\Web\ViewerPage;
use Firefly\Validation\Constraint\ConstraintManifest;
use Firefly\Web\Dispatch\HandlerMethodArgumentResolvers;
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

    /**
     * The resolver registry is the singleton WebServiceProvider binds and every capability's wiring pass
     * add()s into (firefly/security registers its SecurityArgumentResolver there at WiringPasses), so the
     * factory sees the same resolvers the dispatcher consults — and a document generated on demand, after
     * boot, leaves out exactly the parameters those resolvers answer. Injected rather than resolved for the
     * reason RouteManifest is: firefly/web is a hard dependency and its register() has always completed before
     * anything here is built.
     */
    #[Bean]
    #[ConditionalOnMissingBean(OperationFactory::class)]
    public function operationFactory(DtoSchemaFactory $schemas, HandlerMethodArgumentResolvers $resolvers, SecurityModel $security): OperationFactory
    {
        return new OperationFactory($schemas, resolvers: $resolvers, security: $security);
    }

    /**
     * The OPTIONAL Info Object members — summary, termsOfService, contact, license — as their own bean rather
     * than as a few more fields on OpenApiProperties. They are a distinct, spec-shaped object with its own
     * validity rules (a License Object requires a `name`; `identifier` and `url` exclude one another), and
     * keeping them separate is what lets an application replace just this piece — to read a license out of
     * composer.json, say — without also taking over the title and version.
     */
    #[Bean]
    #[ConditionalOnMissingBean(DocumentInfo::class)]
    public function documentInfo(Config $config): DocumentInfo
    {
        return DocumentInfo::fromConfig($config);
    }

    /**
     * The config-driven security contributor: what `firefly.security.*` already says, in the two shapes an
     * OpenAPI document can say it in. It reads the Config PORT and nothing else, so an application with no
     * firefly/security installed simply gets a contributor that answers with nothing — and `deptrac` sees no
     * edge from this package to that one, which is what made publishing the schemes possible at all.
     */
    #[Bean]
    #[ConditionalOnMissingBean(ConfiguredSecurity::class)]
    public function configuredSecurity(Config $config): ConfiguredSecurity
    {
        return new ConfiguredSecurity($config);
    }

    /**
     * Every SecuritySchemeContributor and SecurityRequirementContributor bean in the container, plus the
     * config-driven one this package ships. Collected through the Firefly container facade's getAll() — the
     * DataAutoConfiguration::proxyPlan() idiom, and available here for the same reason: the facade is bound
     * at FlushDefinitions (650), before anything resolves these beans — so firefly/security-oauth2-server's
     * contributor joins simply by being a #[Component], with no registration list to keep in sync.
     *
     * The shipped one is seeded FIRST and skipped in the loop, so an application that turns it into a
     * #[Component] of its own does not get it twice, and so its opinion is the first writer for a scheme
     * name two contributors both claim.
     */
    #[Bean]
    #[ConditionalOnMissingBean(SecurityModel::class)]
    public function securityModel(FireflyContainer $beans, ConfiguredSecurity $configured, Config $config): SecurityModel
    {
        $schemes = [$configured];
        foreach ($beans->getAll(SecuritySchemeContributor::class) as $contributor) {
            if ($contributor instanceof SecuritySchemeContributor && ! $contributor instanceof ConfiguredSecurity) {
                $schemes[] = $contributor;
            }
        }

        $requirements = [$configured];
        foreach ($beans->getAll(SecurityRequirementContributor::class) as $contributor) {
            if ($contributor instanceof SecurityRequirementContributor && ! $contributor instanceof ConfiguredSecurity) {
                $requirements[] = $contributor;
            }
        }

        return new SecurityModel($schemes, $requirements, $config);
    }

    #[Bean]
    #[ConditionalOnMissingBean(OpenApiGenerator::class)]
    public function openApiGenerator(RouteManifest $routes, OpenApiProperties $properties, OperationFactory $operations, DocumentInfo $info, SecurityModel $security): OpenApiGenerator
    {
        return new OpenApiGenerator($routes, $properties, $operations, $info, $security);
    }

    #[Bean]
    #[ConditionalOnMissingBean(ViewerPage::class)]
    public function viewerPage(OpenApiProperties $properties): ViewerPage
    {
        return new ViewerPage($properties->title);
    }
}

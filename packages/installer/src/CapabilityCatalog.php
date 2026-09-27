<?php

declare(strict_types=1);

namespace Firefly\Installer;

use InvalidArgumentException;

/**
 * The catalog behind `--with=` and the interactive picker: capability id -> component requirement.
 *
 * The root library ships all component code. This curated list distinguishes application capabilities
 * from framework plumbing, rather than presenting every source directory as a user choice. The skeleton
 * requires the root package, so its manifest alone cannot describe those choices.
 *
 * CapabilityCatalogTest checks every internal module descriptor against this list and corePackages().
 * Adapter selection remains a configuration decision; the testing capability also adds its external
 * development harness through ArchetypeApplier.
 */
final class CapabilityCatalog
{
    /**
     * Capability id => Capability. Ordered as the interactive picker shows them: the everyday choices
     * first, then the infrastructure adapters, then the dev-only test kit.
     *
     * @return array<string, Capability>
     */
    public static function all(): array
    {
        $capabilities = [
            new Capability('security', 'firefly/security', 'Authentication, method security, JWT + in-memory principals'),
            new Capability('security-oauth2-client', 'firefly/security-oauth2-client', 'OAuth2 client and OpenID Connect login: Google/GitHub/Okta/Keycloak/Microsoft presets, PKCE login, id-token validation, OidcUser principals', requires: ['security']),
            new Capability('security-oauth2-server', 'firefly/security-oauth2-server', 'OAuth 2.1 / OIDC authorization server on top of security: clients, PKCE + consent, JWT access tokens, JWKS, introspection, revocation', requires: ['security']),
            new Capability('validation', 'firefly/validation', 'validate() port, financial Rule objects, #[Valid] interception'),
            new Capability('data', 'firefly/data', '#[Transactional] interception and the transaction manager'),
            new Capability('domain', 'firefly/domain', 'DDD building blocks: Entity, ValueObject, AggregateRoot, DomainEvent'),
            new Capability('cqrs', 'firefly/cqrs', 'CommandBus/QueryBus mediator with attribute-discovered handlers'),
            new Capability('eda', 'firefly/eda', 'Event-driven architecture: EventPublisher port, #[EventListener], retry + DLQ'),
            new Capability('messaging', 'firefly/messaging', 'Raw-bytes MessageBrokerPort with in-memory and queue adapters'),
            new Capability('scheduling', 'firefly/scheduling', '#[Scheduled] tasks behind a DistributedLock port (a ShedLock analog)'),
            new Capability('resilience', 'firefly/resilience', 'Retry, CircuitBreaker, RateLimiter, Fallback, Bulkhead, TimeLimiter'),
            new Capability('actuator', 'firefly/actuator', 'Health, info and introspection endpoints over HTTP'),
            new Capability('observability', 'firefly/observability', 'MeterRegistry with Prometheus text exposition'),
            new Capability('admin', 'firefly/admin', 'Server-rendered dashboard over the actuator (a Spring Boot Admin analog)'),
            new Capability('openapi', 'firefly/openapi', 'OpenAPI 3.1 document generated from the route and constraint manifests, plus a viewer'),

            new Capability('eda-kafka', 'firefly/eda-kafka', 'Kafka publisher/consumer over ext-rdkafka', requires: ['eda'], adapter: true),
            new Capability('eda-rabbitmq', 'firefly/eda-rabbitmq', 'RabbitMQ publisher/consumer over php-amqplib', requires: ['eda'], adapter: true),
            new Capability('eda-postgres', 'firefly/eda-postgres', 'Postgres same-transaction outbox publisher', requires: ['eda'], adapter: true),
            new Capability('scheduling-postgres', 'firefly/scheduling-postgres', 'Postgres advisory-lock DistributedLock backend', requires: ['scheduling'], adapter: true),

            new Capability('testing', 'firefly/testing', 'The first-party test kit: boot harness, sqlite fixtures, assertions', dev: true),
        ];

        $byId = [];
        foreach ($capabilities as $capability) {
            $byId[$capability->id] = $capability;
        }

        return $byId;
    }

    /**
     * The firefly/* packages that are deliberately NOT capabilities, with the reason each one is excluded.
     * CapabilityCatalogTest reads this to prove the catalog covers packages/* exhaustively.
     *
     * @return array<string, string> package name => why it is not selectable
     */
    public static function corePackages(): array
    {
        return [
            'firefly/kernel' => 'the zero-dependency foundation — every app has it',
            'firefly/container' => 'attribute DI is the framework, not an option',
            'firefly/config' => 'profiles and #[ConfigProperties] are the framework, not an option',
            'firefly/context' => 'the boot engine',
            'firefly/autoconfigure' => 'the conditional auto-configuration engine',
            'firefly/web' => 'the HTTP layer; both the api and web archetypes route through it',
            'firefly/cli' => 'the developer console the skeleton already requires directly',
            'firefly/firefly' => 'the root library containing every component',
            'firefly/installer' => 'this tool',
        ];
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::all());
    }

    /**
     * Expand a user selection into the packages to write: unknown ids are rejected loudly, and every
     * capability's `requires` are pulled in transitively so `--with=eda-kafka` cannot produce a project
     * with a Kafka adapter and no EventPublisher port for it to implement.
     *
     * @param  list<string>  $ids
     * @return list<Capability> in catalog order, deduplicated
     *
     * @throws InvalidArgumentException on an unknown id
     */
    public static function resolve(array $ids): array
    {
        $catalog = self::all();
        $selected = [];

        $queue = $ids;
        while ($queue !== []) {
            $id = strtolower(trim((string) array_shift($queue)));
            if ($id === '' || isset($selected[$id])) {
                continue;
            }
            if (! isset($catalog[$id])) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown capability "%s". Available: %s.',
                    $id,
                    implode(', ', self::ids()),
                ));
            }
            $selected[$id] = true;
            foreach ($catalog[$id]->requires as $implied) {
                $queue[] = $implied;
            }
        }

        return array_values(array_filter(
            $catalog,
            static fn (Capability $capability): bool => isset($selected[$capability->id]),
        ));
    }

    /**
     * What `--full` pre-wires: every capability EXCEPT the infrastructure adapters.
     *
     * An adapter is a binding decision, not a capability: `--full` cannot know whether this app publishes
     * over Kafka, RabbitMQ or a Postgres outbox, and picking one for the user would install a broker client
     * (php-amqplib) or demand a PHP extension (ext-rdkafka) that the machine may not have. The port ships;
     * the adapter is an explicit `--with=eda-kafka` away.
     *
     * @return list<Capability>
     */
    public static function full(): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (Capability $capability): bool => ! $capability->adapter,
        ));
    }
}

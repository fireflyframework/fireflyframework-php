<?php

declare(strict_types=1);

namespace Firefly\Installer;

use InvalidArgumentException;

/**
 * The catalog behind `--with=` and the interactive dependency picker: capability id -> firefly/* package.
 *
 * WHY A DECLARATIVE MAP RATHER THAN A DIRECTORY SCAN
 * --------------------------------------------------
 * The obvious implementation is "list packages/* and offer every firefly package you find". It cannot work
 * here, and not for a stylistic reason: firefly/installer is a GLOBAL install. `composer global require
 * firefly/installer` puts this binary in ~/.composer/vendor with symfony/console + symfony/process and
 * nothing else — there is no monorepo checkout on that machine, no packages/ directory to enumerate, and no
 * firefly runtime package to introspect. The installer runs BEFORE the framework exists on disk.
 *
 * The second candidate, "read the list out of the skeleton's composer.json", fails for a different reason:
 * the skeleton requires exactly `firefly/cli` + `firefly/firefly`. firefly/firefly is the runtime BOM (the
 * Composer analog of a Maven BOM) and firefly/cli transitively drags most of the family behind it, so the
 * skeleton's require block names TWO packages and describes seventeen. There is no capability list in it to
 * read, and reading one would require resolving the dependency graph — i.e. running Composer — before we
 * are allowed to ask the user anything.
 *
 * So the map below is owned here, and the rot it invites is handled where it can actually be caught: the
 * CapabilityCatalogTest enumerates the REAL packages/* directory in the monorepo and fails the build if any
 * firefly/* package there is neither a capability nor listed in self::corePackages(). Adding a package to
 * the family therefore forces a deliberate decision — "is this something a user picks?" — instead of
 * silently going missing from the installer for a year. The enumeration still happens; it happens at CI
 * time, where the monorepo exists, rather than at install time, where it does not.
 *
 * WHAT IS NOT A CAPABILITY
 * ------------------------
 * kernel/container/config/context/autoconfigure/web/cli are the framework itself — an app without them is
 * not a LaraFly app, so offering them as opt-ins would be offering the user a way to build something
 * broken. firefly/firefly is the BOM that ships them, and firefly/installer is this tool.
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
            'firefly/firefly' => 'the runtime BOM that ships the family in one line',
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

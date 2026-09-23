<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Support;

use Firefly\Actuator\ActuatorServiceProvider;
use Firefly\Actuator\ActuatorWiringProvider;
use Firefly\Testing\Boot\FireflyBoot;
use Illuminate\Foundation\Application;
use RuntimeException;

/**
 * The security capstone with firefly/actuator mounted beside it, configured the way an operator who wants
 * `when-authorized` health details actually configures it: `/actuator/health` is permitAll (a liveness probe
 * has no credentials and must still read the aggregate status), `show-details` is `when-authorized`, and
 * `firefly.management.endpoint.health.roles` names ROLE_ACTUATOR. Everything the assertion depends on —
 * the filters, the session/Basic authentication, the route, the endpoint, the port and its filler — is the
 * real shipped wiring booted under Testbench; nothing here stubs the decision under test.
 *
 * SIGNING IN IS HTTP BASIC, not a pre-seeded SecurityContextHolder. The holder is populated by the auth
 * filters and cleared by them in a `finally`, so a context set before the request would prove nothing about
 * the path a real caller takes; a Basic header goes through HttpBasicFilter exactly as a curl would, which
 * is the point of a capstone. The three memory principals are named after what they hold, and the
 * hierarchy rule ROLE_ADMIN > ROLE_ACTUATOR is configured so the implication case has something to imply.
 */
abstract class SecuredHealthCapstoneTestCase extends SecurityCapstoneTestCase
{
    /** Each seeded principal by the authority list it holds — the lookup signIn() resolves against. */
    private const PRINCIPALS = [
        'ROLE_ACTUATOR' => 'ops',
        'ROLE_USER' => 'ada',
        'ROLE_ADMIN' => 'root',
    ];

    protected function fireflyProviders(): array
    {
        return [
            ...parent::fireflyProviders(),
            ActuatorServiceProvider::class,
            ActuatorWiringProvider::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function securityOverrides(): array
    {
        return [
            'firefly.security.http_basic.enabled' => true,
            'firefly.security.role_hierarchy' => ['ROLE_ADMIN > ROLE_ACTUATOR'],
            'firefly.security.http.rules' => [
                // A probe reaches the endpoint without credentials; what it may READ is the authorizer's call.
                ['pattern' => 'actuator/health', 'access' => 'permitAll'],
                ['pattern' => '*', 'access' => 'authenticated'],
            ],
            'firefly.security.users' => [
                'ops' => ['password' => '{bcrypt}'.password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]), 'authorities' => ['ROLE_ACTUATOR']],
                'ada' => ['password' => '{bcrypt}'.password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]), 'authorities' => ['ROLE_USER']],
                'root' => ['password' => '{bcrypt}'.password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]), 'authorities' => ['ROLE_ADMIN']],
            ],
            'firefly.management.enabled' => true,
            'firefly.management.endpoints.web.exposure.include' => 'health,info',
            'firefly.management.endpoint.health.db.enabled' => true,
            'firefly.management.endpoint.health.show-details' => 'when-authorized',
            'firefly.management.endpoint.health.roles' => ['ACTUATOR'],
        ];
    }

    /**
     * ScheduledTasksEndpoint is #[ConditionalOnClass(ScheduledManifest)] and firefly/scheduling is a hard
     * dependency of firefly/actuator, so it survives condition filtering and is eagerly resolved at
     * BootPhase::EagerSingletons — with no Scheduling provider registered here, it needs the canonical empty
     * stub, exactly as ActuatorCapstoneTestCase binds it.
     */
    protected function defineFireflyEnvironment(Application $app): void
    {
        parent::defineFireflyEnvironment($app);

        FireflyBoot::stubScheduledManifest($app);
    }

    /**
     * Authenticate the following requests as the seeded principal holding exactly these authorities.
     *
     * @param  list<string>  $authorities
     */
    public function signIn(array $authorities): static
    {
        $key = implode(',', $authorities);
        $username = self::PRINCIPALS[$key] ?? throw new RuntimeException("No seeded principal holds [{$key}].");

        return $this->withHeader('Authorization', 'Basic '.base64_encode($username.':secret'));
    }
}

<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Support;

use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Data\DataServiceProvider;
use Firefly\Security\SecurityServiceProvider;
use Firefly\Security\SecurityWiringProvider;
use Firefly\Testing\Double\RecordingAuthenticationEvents;
use Firefly\Testing\FireflyDatabaseTestCase;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Web\WebServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Session\SessionManager;

/**
 * The REAL pipeline for every security flow: the shipped providers of web, data, cqrs and security booted
 * under Testbench, the fixtures under tests/Fixtures scanned in-process (routes, components, method-security
 * rules and the proxy plan all come from `firefly.scan.paths`, exactly as an uncached application boots), a
 * FILE session driver in a per-class temp directory so a session genuinely survives between requests only
 * when its cookie is carried, and a recording ApplicationEventPublisher bound BEFORE boot so the
 * AuthenticationEventPublisher bean wraps it.
 *
 * Two memory users: ada/secret (ROLE_USER) and root/secret (ROLE_ADMIN), bcrypt at cost 4 — the encoder
 * verifies any cost — plus lock/secret, a locked account for the failure flows. URL rules: /open and /open/*
 * are public (test-only routes go there), everything else needs a principal. Subclasses add keys through
 * securityOverrides() and scan more fixtures through fixturePaths(); both are read before boot.
 */
abstract class SecurityCapstoneTestCase extends FireflyDatabaseTestCase
{
    use SecurityFlows;

    public RecordingAuthenticationEvents $events;

    protected function fireflyProviders(): array
    {
        return [
            ValidationServiceProvider::class,
            WebServiceProvider::class,
            DataServiceProvider::class,
            CqrsServiceProvider::class,
            CqrsWiringProvider::class,
            SecurityServiceProvider::class,
            SecurityWiringProvider::class,
        ];
    }

    /** @return array<string,string> */
    protected function fixturePaths(): array
    {
        return ['Firefly\\Security\\Tests\\Fixtures\\Flows\\' => dirname(__DIR__).'/Fixtures/Flows'];
    }

    /** @return array<string, mixed> extra firefly.security.* keys, seeded before boot */
    protected function securityOverrides(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            'app.env' => 'testing',
            'app.debug' => false,
            'session.driver' => 'file',
            'session.files' => $this->sessionDir(),
            'session.cookie' => 'firefly_session',
            'firefly.scan.paths' => $this->fixturePaths(),
            'firefly.security.enabled' => true,
            'firefly.security.http.enabled' => true,
            'firefly.security.http.rules' => [
                ['pattern' => 'open', 'access' => 'permitAll'],
                ['pattern' => 'open/*', 'access' => 'permitAll'],
                ['pattern' => 'api/*', 'access' => 'authenticated'],
                ['pattern' => '*', 'access' => 'authenticated'],
            ],
            'firefly.security.users' => [
                'ada' => ['password' => '{bcrypt}'.password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]), 'authorities' => ['ROLE_USER']],
                'root' => ['password' => '{bcrypt}'.password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]), 'authorities' => ['ROLE_ADMIN']],
                'lock' => ['password' => '{bcrypt}'.password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]), 'authorities' => ['ROLE_USER'], 'locked' => true],
            ],
            ...$this->securityOverrides(),
        ];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        $this->events = new RecordingAuthenticationEvents;
        $app->instance(ApplicationEventPublisher::class, $this->events);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Boot publishes its own lifecycle events through the same port; start every test from silence.
        $this->events->reset();
    }

    protected function sessionDir(): string
    {
        $dir = sys_get_temp_dir().'/firefly-security-sessions-'.str_replace('\\', '_', static::class);
        if (! is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }

        return $dir;
    }

    /**
     * Forget the in-memory session store, as a new PHP process would: the next request sees only what the
     * FILE holds for the cookie it carries. Without this, Laravel's session Store singleton keeps the last
     * request's attributes in memory and a flow test could pass without ever persisting anything.
     */
    protected function forgetSession(): void
    {
        /** @var SessionManager $manager */
        $manager = $this->app()->make('session');
        $manager->forgetDrivers();
    }
}

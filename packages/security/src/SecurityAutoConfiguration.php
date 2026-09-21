<?php

declare(strict_types=1);

namespace Firefly\Security;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\Cqrs\Handler\HandlerManifest;
use Firefly\Cqrs\Security\CommandAuthorizer;
use Firefly\Cqrs\Security\QueryAuthorizer;
use Firefly\Data\Repository\Auditing\AuditorAware;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\Access\AuthorizationChecker;
use Firefly\Security\Access\DenyAllPermissionEvaluator;
use Firefly\Security\Access\Expression\SecurityExpressionEvaluator;
use Firefly\Security\Access\HttpSecurity;
use Firefly\Security\Access\Method\MethodSecurityEvaluator;
use Firefly\Security\Access\Method\MethodSecurityInterceptor;
use Firefly\Security\Access\Method\SecurityMethodManifest;
use Firefly\Security\Access\PermissionEvaluator;
use Firefly\Security\Access\RoleHierarchy;
use Firefly\Security\Authentication\AuthenticationManager;
use Firefly\Security\Authentication\DaoAuthenticationProvider;
use Firefly\Security\Authentication\ProviderManager;
use Firefly\Security\Cqrs\MethodSecurityMessageEnforcer;
use Firefly\Security\Cqrs\SecurityCommandAuthorizer;
use Firefly\Security\Cqrs\SecurityQueryAuthorizer;
use Firefly\Security\Data\SecurityContextAuditorAware;
use Firefly\Security\Event\AuthenticationEventPublisher;
use Firefly\Security\Jwt\JwtService;
use Firefly\Security\OAuth2\JwksDocumentSource;
use Firefly\Security\OAuth2\JwksProvider;
use Firefly\Security\OAuth2\JwksUri;
use Firefly\Security\OAuth2\LocalJwksProvider;
use Firefly\Security\OAuth2\RemoteJwksProvider;
use Firefly\Security\Password\Argon2idPasswordEncoder;
use Firefly\Security\Password\BcryptPasswordEncoder;
use Firefly\Security\Password\DelegatingPasswordEncoder;
use Firefly\Security\Password\NoOpPasswordEncoder;
use Firefly\Security\Password\PasswordEncoder;
use Firefly\Security\Session\SecurityContextRepository;
use Firefly\Security\Session\SessionSecurityContextRepository;
use Firefly\Security\Session\SessionSecuritySettings;
use Firefly\Security\User\InMemoryUserDetailsService;
use Firefly\Security\User\UserDetailsService;
use Firefly\Security\Web\Csrf\SessionCsrf;
use Firefly\Security\Web\Settings\FormLoginSettings;
use Firefly\Security\Web\Settings\HttpBasicSettings;
use Firefly\Security\Web\Settings\LogoutSettings;
use Firefly\Security\Web\Settings\RememberMeSettings;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;

/**
 * The opt-in, secure-by-default bean source. The master flag firefly.security.enabled gates the core stack
 * (principal evaluator, role hierarchy, user store, authentication manager, the CQRS authorizers, the AuditorAware,
 * the programmatic checker); each surface (jwt/oauth2/http) has its own sub-flag so enabling security does not
 * force a JWT secret. #[Order(500)] is DELIBERATELY below CqrsAutoConfiguration's #[Order(1000)]: the incremental
 * condition pass evaluates auto-configs low-#[Order]-first and registers survivors before the next is evaluated,
 * so the Security CommandAuthorizer/QueryAuthorizer register FIRST and Cqrs's #[ConditionalOnMissingBean] then
 * backs off (the AllowAll default steps aside) — the same explicit-precedence mechanism scheduling-postgres uses.
 * Every bean is additionally #[ConditionalOnMissingBean] so a user override always wins over the framework default.
 */
#[Configuration]
#[Order(500)]
final class SecurityAutoConfiguration
{
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(PasswordEncoder::class)]
    public function passwordEncoder(): PasswordEncoder
    {
        return new DelegatingPasswordEncoder('bcrypt', [
            'bcrypt' => new BcryptPasswordEncoder,
            'argon2id' => new Argon2idPasswordEncoder,
            'noop' => new NoOpPasswordEncoder,
        ]);
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(UserDetailsService::class)]
    public function userDetailsService(Config $config): UserDetailsService
    {
        /** @var array<string,array{password:string,authorities?:list<string>,enabled?:bool,locked?:bool}> $users */
        $users = $config->has('firefly.security.users') ? $config->array('firefly.security.users') : [];

        return InMemoryUserDetailsService::fromConfig($users);
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(RoleHierarchy::class)]
    public function roleHierarchy(Config $config): RoleHierarchy
    {
        /** @var list<string> $rules */
        $rules = $config->has('firefly.security.role_hierarchy') ? $config->array('firefly.security.role_hierarchy') : [];

        return RoleHierarchy::fromRules($rules);
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(PermissionEvaluator::class)]
    public function permissionEvaluator(): PermissionEvaluator
    {
        return new DenyAllPermissionEvaluator;
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(SecurityExpressionEvaluator::class)]
    public function securityExpressionEvaluator(): SecurityExpressionEvaluator
    {
        return new SecurityExpressionEvaluator;
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(AuthenticationManager::class)]
    public function authenticationManager(UserDetailsService $users, PasswordEncoder $encoder): AuthenticationManager
    {
        return new ProviderManager([new DaoAuthenticationProvider($users, $encoder)]);
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(AuthorizationChecker::class)]
    public function authorizationChecker(SecurityExpressionEvaluator $evaluator, RoleHierarchy $roles, PermissionEvaluator $permissions): AuthorizationChecker
    {
        return new AuthorizationChecker($evaluator, $roles, $permissions);
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(MethodSecurityEvaluator::class)]
    public function methodSecurityEvaluator(SecurityExpressionEvaluator $evaluator, RoleHierarchy $roles, PermissionEvaluator $permissions, AuthenticationEventPublisher $events, ?LoggerInterface $logger = null): MethodSecurityEvaluator
    {
        return new MethodSecurityEvaluator($evaluator, $roles, $permissions, $events, $logger);
    }

    /**
     * The proxy link for #[PreAuthorize]/#[PostAuthorize]/#[Secured]/#[RolesAllowed]/#[PreFilter]/#[PostFilter]
     * on any stereotyped bean. Gated by the master flag AND `firefly.security.method.enabled` (default true):
     * with either off the bean is absent and every planned proxy runs a pass-through in its place — the same
     * "annotations are inert until security is on" rule the controller guard has always had.
     */
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
    #[ConditionalOnProperty(name: 'firefly.security.method.enabled', havingValue: 'true', matchIfMissing: true)]
    #[ConditionalOnMissingBean(MethodSecurityInterceptor::class)]
    public function methodSecurityInterceptor(MethodSecurityEvaluator $evaluator, Config $config): MethodSecurityInterceptor
    {
        return new MethodSecurityInterceptor($evaluator, $config);
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(MethodSecurityMessageEnforcer::class)]
    public function methodSecurityMessageEnforcer(HandlerManifest $handlers, SecurityMethodManifest $methods, SecurityExpressionEvaluator $evaluator, RoleHierarchy $roles, PermissionEvaluator $permissions, ?LoggerInterface $logger = null, ?AuthenticationEventPublisher $events = null): MethodSecurityMessageEnforcer
    {
        return new MethodSecurityMessageEnforcer($handlers, $methods, $evaluator, $roles, $permissions, $logger, $events);
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(CommandAuthorizer::class)]
    public function commandAuthorizer(MethodSecurityMessageEnforcer $enforcer): CommandAuthorizer
    {
        return new SecurityCommandAuthorizer($enforcer);
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(QueryAuthorizer::class)]
    public function queryAuthorizer(MethodSecurityMessageEnforcer $enforcer): QueryAuthorizer
    {
        return new SecurityQueryAuthorizer($enforcer);
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(AuditorAware::class)]
    public function auditorAware(): AuditorAware
    {
        return new SecurityContextAuditorAware;
    }

    /**
     * The one publisher every mechanism reports through. It wraps whatever ApplicationEventPublisher is bound
     * — the DispatcherEventPublisher in an application, a recording double in a test — so security events are
     * ordinary application events: #[AsEventListener] methods receive them and Event::fake() intercepts them.
     */
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(AuthenticationEventPublisher::class)]
    public function authenticationEventPublisher(ApplicationEventPublisher $events): AuthenticationEventPublisher
    {
        return new AuthenticationEventPublisher($events);
    }

    /**
     * The session half: whether the SecurityContext is carried between requests (read live), and where it is
     * kept. The repository is the Laravel session unless the application binds its own.
     */
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(SessionSecuritySettings::class)]
    public function sessionSecuritySettings(Config $config): SessionSecuritySettings
    {
        return new SessionSecuritySettings($config);
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(SecurityContextRepository::class)]
    public function securityContextRepository(): SecurityContextRepository
    {
        return new SessionSecurityContextRepository;
    }

    /**
     * The settings every web mechanism reads, each a value object built once from its `firefly.security.*`
     * block. Master-gated like the filters that consume them; RememberMeSettings::fromConfig() refuses a weak
     * key when remember-me is on, the same rule the JWT secret is held to.
     */
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(FormLoginSettings::class)]
    public function formLoginSettings(Config $config): FormLoginSettings
    {
        return FormLoginSettings::fromConfig($config);
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(HttpBasicSettings::class)]
    public function httpBasicSettings(Config $config): HttpBasicSettings
    {
        return HttpBasicSettings::fromConfig($config);
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(LogoutSettings::class)]
    public function logoutSettings(Config $config): LogoutSettings
    {
        return LogoutSettings::fromConfig($config);
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(RememberMeSettings::class)]
    public function rememberMeSettings(Config $config): RememberMeSettings
    {
        return RememberMeSettings::fromConfig($config);
    }

    /**
     * The session-token CSRF check CsrfFilter makes on a session-backed request and the interactive
     * mechanisms (login, logout) make before anything else. DELIBERATELY NOT GATED BY A PROPERTY: its
     * consumers are, and their gates do not nest — CsrfFilter needs it under `csrf.enabled` alone (no master
     * flag), the login and logout filters under the master flag — so the bean is simply there, costs nothing
     * (it holds the container and resolves the Encrypter on the first X-XSRF-TOKEN it decrypts, so an empty
     * APP_KEY at `key:generate` time is not a boot failure), and yields to an application override like every
     * other bean here.
     */
    #[Bean]
    #[ConditionalOnMissingBean(SessionCsrf::class)]
    public function sessionCsrf(Container $container): SessionCsrf
    {
        return new SessionCsrf($container);
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.jwt.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(JwtService::class)]
    public function jwtService(Config $config): JwtService
    {
        return new JwtService(
            $config->string('firefly.security.jwt.secret'),
            $config->string('firefly.security.jwt.algorithm', 'HS256'),
            $config->int('firefly.security.jwt.leeway', 0),
        );
    }

    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.http.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(HttpSecurity::class)]
    public function httpSecurity(Config $config): HttpSecurity
    {
        /** @var list<array{pattern:string,access:string}> $rules */
        $rules = $config->has('firefly.security.http.rules') ? $config->array('firefly.security.http.rules') : [];

        return HttpSecurity::fromConfig($rules);
    }

    /**
     * WHERE THE KEYS COME FROM: `firefly.security.oauth2.resource_server.jwks_source` is `auto` (default),
     * `local` or `remote`. `local` answers from a JwksDocumentSource bound by the application — the key set
     * it signs its own tokens with — and refuses to boot when none is bound; `remote` fetches `jwks_uri`
     * over HTTP with bounded timeouts; `auto` picks `local` when a source is bound AND the URI names this
     * application (JwksUri::isOwn), and `remote` otherwise. See LocalJwksProvider for why a server must never
     * fetch its own keys from itself.
     */
    #[Bean]
    #[ConditionalOnProperty(name: 'firefly.security.oauth2.resource_server.enabled', havingValue: 'true')]
    #[ConditionalOnMissingBean(JwksProvider::class)]
    public function jwksProvider(Config $config, Container $container): JwksProvider
    {
        $jwksUri = $config->string('firefly.security.oauth2.resource_server.jwks_uri');
        $source = $config->string('firefly.security.oauth2.resource_server.jwks_source', 'auto');
        $hasLocal = $container->bound(JwksDocumentSource::class);

        $local = match ($source) {
            'local' => $hasLocal ? true : throw new ConfigurationException(
                'firefly.security.oauth2.resource_server.jwks_source is `local` but no '.JwksDocumentSource::class.' is bound. Bind the key set this application signs with, or set jwks_source to `remote`.',
            ),
            'remote' => false,
            'auto' => $hasLocal && JwksUri::isOwn(
                $jwksUri,
                $config->string('app.url', ''),
                $config->int('firefly.server.port', 0),
            ),
            default => throw new ConfigurationException(
                "firefly.security.oauth2.resource_server.jwks_source must be one of auto, local or remote; got `{$source}`.",
            ),
        };

        if ($local) {
            /** @var JwksDocumentSource $documentSource */
            $documentSource = $container->make(JwksDocumentSource::class);

            return new LocalJwksProvider($documentSource);
        }

        /** @var Cache $cache */
        $cache = $container->make(Cache::class);

        return new RemoteJwksProvider(
            $jwksUri,
            $cache,
            $config->int('firefly.security.oauth2.resource_server.cache_ttl', 3600),
            $config->int('firefly.security.oauth2.resource_server.jwks_connect_timeout', RemoteJwksProvider::DEFAULT_CONNECT_TIMEOUT_SECONDS),
            $config->int('firefly.security.oauth2.resource_server.jwks_timeout', RemoteJwksProvider::DEFAULT_TIMEOUT_SECONDS),
        );
    }
}

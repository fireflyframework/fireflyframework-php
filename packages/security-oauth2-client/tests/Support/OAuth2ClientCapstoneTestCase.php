<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Tests\Support;

use Firefly\Security\OAuth2\Client\SecurityOAuth2ClientServiceProvider;
use Firefly\Security\OAuth2\Client\SecurityOAuth2ClientWiringProvider;
use Firefly\Security\Tests\Support\SecurityCapstoneTestCase;
use Firefly\Testing\Security\OAuth2\FakeAuthorizationServer;
use Illuminate\Foundation\Application;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The security capstone (real providers of web, data, cqrs and security under Testbench, a FILE session, the
 * recording event publisher, the Flows fixtures) plus this package's two providers, the FakeAuthorizationServer
 * installed BEFORE boot at http://localhost/fake-idp (its routes on the app, its back channel on Http::fake),
 * one authorization-code registration `fake` whose provider is discovered from the issuer, and OAuth2 login
 * ON — form login stays OFF, so every page and redirect the suite sees exists because of OAuth2 login alone.
 *
 * URL rules: the fake provider's front channel and /open* are public; /api/scoped needs SCOPE_profile (the
 * hasScope vocabulary); everything else needs a principal. Subclasses adjust the `fake` registration through
 * registrationOverrides()/providerOverrides() and add keys through clientOverrides(); all read before boot.
 */
abstract class OAuth2ClientCapstoneTestCase extends SecurityCapstoneTestCase
{
    public const string ISSUER = 'http://localhost/fake-idp';

    public FakeAuthorizationServer $idp;

    protected function fireflyProviders(): array
    {
        return [...parent::fireflyProviders(), SecurityOAuth2ClientServiceProvider::class, SecurityOAuth2ClientWiringProvider::class];
    }

    protected function fixturePaths(): array
    {
        return [...parent::fixturePaths(), 'Firefly\\Security\\OAuth2\\Client\\Tests\\Fixtures\\Flows\\' => dirname(__DIR__).'/Fixtures/Flows'];
    }

    /** @return array<string, mixed> keys merged over FakeAuthorizationServer::registrationConfig() */
    protected function registrationOverrides(): array
    {
        return [];
    }

    /** @return array<string, mixed> keys merged over FakeAuthorizationServer::providerConfig() */
    protected function providerOverrides(): array
    {
        return [];
    }

    /** @return array<string, mixed> extra firefly.* keys, seeded before boot */
    protected function clientOverrides(): array
    {
        return [];
    }

    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'firefly.security.http.rules' => [
                ['pattern' => 'fake-idp/*', 'access' => 'permitAll'],
                ['pattern' => 'open', 'access' => 'permitAll'],
                ['pattern' => 'open/*', 'access' => 'permitAll'],
                ['pattern' => 'api/scoped', 'access' => 'hasScope:profile'],
                ['pattern' => 'api/*', 'access' => 'authenticated'],
                ['pattern' => '*', 'access' => 'authenticated'],
            ],
            'firefly.security.oauth2.client.enabled' => true,
            'firefly.security.oauth2.client.login.enabled' => true,
            'firefly.security.oauth2.client.registration.fake' => FakeAuthorizationServer::registrationConfig($this->registrationOverrides()),
            'firefly.security.oauth2.client.provider.fake' => FakeAuthorizationServer::providerConfig(self::ISSUER, $this->providerOverrides()),
            ...$this->clientOverrides(),
        ];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        parent::defineFireflyEnvironment($app);

        $this->idp = FakeAuthorizationServer::install($app, self::ISSUER);
    }

    /**
     * GET /oauth2/authorization/{id}: the 302 to the provider, whose cookie is the session holding the request.
     *
     * @return TestResponse<Response>
     */
    public function startLogin(string $registrationId = 'fake'): TestResponse
    {
        $response = $this->get('/oauth2/authorization/'.$registrationId);
        $response->assertRedirect();

        return $response;
    }

    /**
     * Follow a redirect in-process, as the browser would, carrying the session of $session.
     *
     * @param  TestResponse<Response>  $session
     * @param  TestResponse<Response>  $redirect
     * @return TestResponse<Response>
     */
    public function follow(TestResponse $session, TestResponse $redirect): TestResponse
    {
        $this->forgetSession();

        return $this->followSession($session)->get((string) $redirect->headers->get('Location'));
    }

    /**
     * The whole dance — start, the provider's answer, the callback — and the callback's response: a 302 to
     * the success URL whose cookie is the signed-in session.
     *
     * @return TestResponse<Response>
     */
    public function signInThroughProvider(string $registrationId = 'fake'): TestResponse
    {
        $start = $this->startLogin($registrationId);
        $provider = $this->follow($start, $start);
        $provider->assertRedirect();

        return $this->follow($start, $provider);
    }

    /**
     * The query string of a redirect's Location, as strings.
     *
     * @param  TestResponse<Response>  $redirect
     * @return array<string, string>
     */
    public function queryOf(TestResponse $redirect): array
    {
        parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $query);
        $strings = [];
        foreach ($query as $name => $value) {
            if (is_string($value)) {
                $strings[(string) $name] = $value;
            }
        }

        return $strings;
    }

    /**
     * The content of every session file the suite has written — what a person with read access to
     * storage/framework/sessions could read.
     *
     * @return list<string>
     */
    public function sessionFiles(): array
    {
        $contents = [];
        foreach (glob($this->sessionDir().'/*') ?: [] as $file) {
            if (is_file($file)) {
                $contents[] = (string) file_get_contents($file);
            }
        }

        return $contents;
    }
}

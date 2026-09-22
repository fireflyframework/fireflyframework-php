<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Web\Login;

use Firefly\Security\OAuth2\Client\Discovery\ProviderDiscoveryException;
use Firefly\Security\OAuth2\Client\OAuth2ClientSettings;
use Firefly\Security\OAuth2\Client\Registration\AuthorizationGrantType;
use Firefly\Security\OAuth2\Client\Registration\ClientRegistrationRepository;
use Firefly\Security\Web\Login\LoginPageLink;
use Firefly\Security\Web\Login\LoginPageLinks;
use Firefly\Security\Web\Settings\FormLoginSettings;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;

/**
 * One "Sign in with {client_name}" per authorization-code registration, linking to
 * {authorization_endpoint_base_uri}/{id} — root-relative, with the request's base path, for the reason the
 * login form's action is (same-origin with the page whatever a proxy told PHP). Registrations with another
 * grant are not logins and are left out.
 *
 * A registration whose provider cannot be discovered is LEFT OUT rather than taking the whole page down: the
 * page is where every OTHER way in lives, and one provider's trouble must not lock a person out of the
 * password form or another provider (the rule LoginPageAction follows for a view that will not render — a
 * person who cannot sign in cannot fix the configuration). What the log gets depends on WHICH trouble, since
 * the two look identical from the browser — a button that is not there — and mean opposite things to an
 * operator:
 *
 *   - the provider is DOWN (`ProviderDiscoveryException::$transient`: a refused connection, a timeout, a
 *     5xx) — a WARNING naming the registration; the button is back on the first render after the provider
 *     is, because a failed discovery is never cached;
 *   - the provider's document is UNUSABLE (`invalid()`: `issuer` differs from `issuer_uri`, no
 *     `authorization_endpoint`, a body that is not JSON) — an ERROR naming the registration, because nothing
 *     will change until `provider.{id}` or the provider does. With `discovery.eager` off (the default) this
 *     line is the ONLY place a wrong `issuer_uri` shows up short of someone noticing the missing button, so
 *     it is written at the level an alert watches; with it on, the same mistake fails the boot instead.
 *
 * Either way the start URL itself, asked directly, still answers the 503 — only the page degrades. Discovery
 * is memoised once it succeeds, so a healthy provider costs the page nothing per render, and a broken one
 * costs it one bounded fetch (`http.connect_timeout` + `http.timeout`) and one log line per render.
 *
 * EVERY registration is resolved, not only the logins, because the grant — what decides whether a registration
 * is a login at all — is a fact of the resolved registration, and the port's cheap call (registrationIds())
 * does not carry it. A client_credentials registration whose provider is down is therefore reported too: it
 * would have been left out regardless, and the line is worded so it stays true of it — the provider IS down
 * or misconfigured, and the job that uses that registration will find the same.
 */
final class OAuth2LoginPageLinks implements LoginPageLinks
{
    public function __construct(
        private readonly ClientRegistrationRepository $registrations,
        private readonly OAuth2ClientSettings $settings,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function links(Request $request): array
    {
        $base = $request->getBaseUrl().FormLoginSettings::path($this->settings->authorizationEndpointBaseUri);
        $links = [];
        foreach ($this->registrations->registrationIds() as $id) {
            try {
                $registration = $this->registrations->findByRegistrationId($id);
            } catch (ProviderDiscoveryException $e) {
                $this->omitted($id, $e);

                continue;
            }
            if ($registration === null || $registration->authorizationGrantType !== AuthorizationGrantType::AuthorizationCode) {
                continue;
            }
            $links[] = new LoginPageLink($id, $registration->clientName, $base.'/'.$id);
        }

        return $links;
    }

    private function omitted(string $id, ProviderDiscoveryException $e): void
    {
        $context = ['registration' => $id, 'exception' => $e];
        if ($e->transient) {
            $this->logger?->warning("The login page left out [{$id}]: its provider could not be discovered, and is asked again on the next render. {$e->getMessage()}", $context);

            return;
        }

        $this->logger?->error("The login page left out [{$id}]: its provider's discovery document is unusable, which is a misconfiguration and not an outage — no retry fixes it. {$e->getMessage()}", $context);
    }
}

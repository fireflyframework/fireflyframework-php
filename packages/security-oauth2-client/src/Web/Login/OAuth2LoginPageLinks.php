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
 * A registration whose provider cannot be discovered right now is OMITTED, with a warning naming it, rather
 * than taking the whole page down: the page is where every OTHER way in lives, and one provider's outage must
 * not lock a person out of the password form or another provider. Discovery is cached, so the warning is
 * rare and the cost of asking is a memoised lookup.
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
                $this->logger?->warning("The login page omitted the provider [{$id}]: {$e->getMessage()}", ['registration' => $id, 'exception' => $e]);

                continue;
            }
            if ($registration === null || $registration->authorizationGrantType !== AuthorizationGrantType::AuthorizationCode) {
                continue;
            }
            $links[] = new LoginPageLink($id, $registration->clientName, $base.'/'.$id);
        }

        return $links;
    }
}

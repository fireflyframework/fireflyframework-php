<?php

declare(strict_types=1);

/*
 * Run by ClientRegistrationTest in a FRESH php process: when the cloner below is built, nothing but Composer's
 * autoloader has run — no service provider, no test bootstrap — which is the state Laravel's
 * FoundationServiceProvider is in when it builds the cloner behind dd() in its own register(). A caster that only
 * a provider registered would be missing here, exactly as it is missing from the cloner dd() uses.
 */

use Firefly\Security\OAuth2\Client\Registration\AuthorizationGrantType;
use Firefly\Security\OAuth2\Client\Registration\ClientAuthenticationMethod;
use Firefly\Security\OAuth2\Client\Registration\ClientRegistration;
use Firefly\Security\OAuth2\Client\Registration\ProviderDetails;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

require dirname(__DIR__, 4).'/vendor/autoload.php'; // Fixtures -> tests -> security-oauth2-client -> packages -> root

$registration = new ClientRegistration(
    registrationId: 'okta',
    clientId: 'app',
    clientSecret: 'a-very-secret-value',
    clientAuthenticationMethod: ClientAuthenticationMethod::ClientSecretBasic,
    authorizationGrantType: AuthorizationGrantType::AuthorizationCode,
    redirectUri: '{baseUrl}/login/oauth2/code/{registrationId}',
    scopes: ['openid'],
    clientName: 'Okta',
    providerDetails: new ProviderDetails('https://idp.example.com/authorize', 'https://idp.example.com/token'),
);

$dumper = new CliDumper;
$dumper->setColors(false);

echo $dumper->dump((new VarCloner)->cloneVar($registration), true);

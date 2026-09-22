<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Client\Registration\ClientRegistration;
use Symfony\Component\VarDumper\Cloner\AbstractCloner;

/*
 * The VarDumper casters of firefly/security-oauth2-client, registered when Composer's autoloader loads
 * (composer.json autoload.files) and deliberately NOT from a service provider.
 *
 * dd() and dump() are Symfony VarDumper's, and a VarCloner copies AbstractCloner::$defaultCasters in its
 * constructor. Laravel's FoundationServiceProvider builds the cloner behind dd() in its own register(), which runs
 * before any package provider's — Application::registerConfiguredProviders() registers the Illuminate providers
 * first, then the discovered package providers — so a caster added from SecurityOAuth2ClientWiringProvider::
 * register() lands in the static AFTER the cloner dd() uses has taken its copy, and the client secret still
 * prints. Composer's autoloader is the one thing that runs before bootstrap/app.php, which makes this file the
 * only place the caster can be registered from. ClientRegistration's docblock maps every dump door to the hook
 * that closes it; this is the dd()/dump() one.
 *
 * The file declares nothing and the assignment is idempotent, because the component scanner probes every file
 * under src/ with class_exists() and the PSR-4 autoloader includes this one a second time for it. The guard is
 * for a component-only install: symfony/var-dumper is a hard dependency of laravel/framework, not of the
 * illuminate/* packages this package requires.
 */
if (class_exists(AbstractCloner::class)) {
    AbstractCloner::$defaultCasters[ClientRegistration::class] = [ClientRegistration::class, 'castForDumper'];
}

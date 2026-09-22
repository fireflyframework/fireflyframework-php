<?php

declare(strict_types=1);

namespace Firefly\Security\Web\EntryPoint;

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Kernel\Exception\Security\AuthenticationException;
use Firefly\Security\Web\Settings\FormLoginSettings;
use Firefly\Security\Web\Settings\HttpBasicSettings;
use Firefly\Web\Error\ErrorPageRenderer;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `firefly.security.http.entry_point` (Spring's DelegatingAuthenticationEntryPoint, with the request matchers
 * fixed by the framework):
 *
 *   auto      — a BROWSER (the request names text/html, is not an XMLHttpRequest and is not under a
 *               `firefly.web.error-page.json-paths` path — the same negotiation the error page uses) is sent
 *               to the login page when form login or OAuth2 login is on (`FormLoginSettings::$pageEnabled`);
 *               otherwise, when HTTP Basic is on, a 401 challenge; otherwise the 401 problem/page exactly as
 *               before.
 *   login     — always the login page (refused at boot when neither login is on).
 *   challenge — always the Basic challenge.
 *   problem   — always the exception, rendered by firefly/web.
 *
 * THE BROWSER TEST IS `ErrorPageRenderer::prefersHtml()`, NOT `handles()`. The two differ by exactly one
 * thing: `handles()` is also false when `firefly.web.error-page.enabled` is off. That flag is a branding
 * choice — "fall back to Laravel's own error page" — and it decides what a 401 LOOKS like, never whether a
 * person is asked to sign in. Asking `handles()` here made that flag silently disable form login for every
 * browser, with nothing in the security docs to say so; the decision must not depend on how a failure is
 * drawn.
 */
final class DelegatingAuthenticationEntryPoint implements AuthenticationEntryPoint
{
    public const string AUTO = 'auto';

    public const string LOGIN = 'login';

    public const string CHALLENGE = 'challenge';

    public const string PROBLEM = 'problem';

    public function __construct(
        private readonly string $mode,
        private readonly FormLoginSettings $formLogin,
        private readonly HttpBasicSettings $basic,
        private readonly ErrorPageRenderer $pages,
        private readonly LoginUrlAuthenticationEntryPoint $login,
        private readonly BasicAuthenticationEntryPoint $challenge,
        private readonly ProblemAuthenticationEntryPoint $problem,
    ) {}

    public static function modeFrom(Config $config): string
    {
        $mode = $config->string('firefly.security.http.entry_point', self::AUTO);
        if (! in_array($mode, [self::AUTO, self::LOGIN, self::CHALLENGE, self::PROBLEM], true)) {
            throw new ConfigurationException("firefly.security.http.entry_point must be one of auto, login, challenge or problem; got `{$mode}`.");
        }
        if ($mode === self::LOGIN && ! $config->bool('firefly.security.form_login.enabled', false) && ! $config->bool('firefly.security.oauth2.client.login.enabled', false)) {
            throw new ConfigurationException('firefly.security.http.entry_point is `login` but neither firefly.security.form_login.enabled nor firefly.security.oauth2.client.login.enabled is on: there is no login page to send anyone to.');
        }

        return $mode;
    }

    public function commence(Request $request, AuthenticationException $exception): Response
    {
        return match ($this->mode) {
            self::LOGIN => $this->login->commence($request, $exception),
            self::CHALLENGE => $this->challenge->commence($request, $exception),
            self::PROBLEM => $this->problem->commence($request, $exception),
            default => $this->negotiate($request)->commence($request, $exception),
        };
    }

    private function negotiate(Request $request): AuthenticationEntryPoint
    {
        if ($this->formLogin->pageEnabled && $this->pages->prefersHtml($request)) {
            return $this->login;
        }
        if ($this->basic->enabled) {
            return $this->challenge;
        }

        return $this->problem;
    }
}

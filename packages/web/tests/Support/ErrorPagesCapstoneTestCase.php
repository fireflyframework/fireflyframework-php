<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Support;

/**
 * The web capstone with every error-page key this wave added turned on, so the pipeline the tests drive is
 * the one an application configures rather than a default nobody chose.
 *
 * `trace` is set EXPLICITLY rather than left to follow `app.debug`, for the same reason the browser harness
 * does it: a suite that means to test the debug page must not render the production one because something
 * upstream decided what `app.debug` is.
 */
abstract class ErrorPagesCapstoneTestCase extends WebCapstoneTestCase
{
    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'app.env' => $this->environment(),
            'firefly.web.error-page.enabled' => true,
            'firefly.web.error-page.trace' => $this->trace(),
            'firefly.web.error-page.home' => '/',
            'firefly.web.error-page.sign-in' => '/login',
            'firefly.web.error-page.support' => 'https://support.example.test',
            'firefly.web.error-page.max-frames' => 25,
            'firefly.web.problem.disclose' => false,
            'firefly.web.problem.type-uri' => 'https://api.example.test/problems',
        ];
    }

    /** Whether the page carries the exception, its source and its trace. */
    protected function trace(): bool
    {
        return true;
    }

    /** `app.env` — the hint footer follows the environment, not the trace key. */
    protected function environment(): string
    {
        return 'local';
    }
}

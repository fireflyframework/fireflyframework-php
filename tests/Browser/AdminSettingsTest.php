<?php

declare(strict_types=1);

use Firefly\Admin\Settings\SettingsConsole;
use Firefly\Tests\Browser\Support\BrowserTestCase;

pest()->extend(BrowserTestCase::class);

afterEach(function (): void {
    /** @var BrowserTestCase $this */
    // The console was rebound by the fixture to write under the compile temp dir; still, an override must
    // not survive into the next test.
    $this->app()->make(SettingsConsole::class)->reset();
});

it('lists the switches grouped by area and turns one off through the form', function (): void {
    /** @var BrowserTestCase $this */
    $row = 'tr:has(input[name="key"][value="firefly.admin.data.relations"])';
    $form = 'form:has(input[name="key"][value="firefly.admin.data.relations"])';

    $page = visit('/firefly/settings');

    $page->assertSee('Feature switches')
        ->assertSee('Dashboard')
        ->assertSee('Observability')
        ->assertSee('firefly.admin.data.relations')
        ->assertDontSee('Active overrides')
        ->assertSeeIn($row.' span.bool', 'on')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'settings');

    // The relations switch's own form: the row's hidden `key` input identifies it; the button is the
    // only submit in that form.
    $page->click($form.' button')
        ->assertSee('Feature switches')
        ->assertSee('Active overrides')
        ->assertSeeIn($row.' span.bool', 'off')
        ->assertSeeIn($form.' button', 'Turn on')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'settings-after-toggle');

    $console = $this->app()->make(SettingsConsole::class);

    expect(is_file($console->file()))->toBeTrue()
        ->and(str_starts_with($console->file(), sys_get_temp_dir()))->toBeTrue()
        ->and($console->overrides())->toHaveKey('firefly.admin.data.relations', false);
});

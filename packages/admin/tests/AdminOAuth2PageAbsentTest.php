<?php

declare(strict_types=1);

use Firefly\Admin\Tests\Support\AdminCapstoneTestCase;

uses(AdminCapstoneTestCase::class);

it('hides the OAuth2 page and answers its address with the unavailable page while no oauth2clients endpoint is registered', function () {
    /** @var AdminCapstoneTestCase $this */
    $this->get('/firefly')->assertOk()->assertDontSee('OAuth2 clients');
    $this->get('/firefly/oauth2')->assertStatus(404)->assertSee('OAuth2 clients');
});

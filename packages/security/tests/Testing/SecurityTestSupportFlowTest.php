<?php

declare(strict_types=1);

use Firefly\Cqrs\Command\CommandBus;
use Firefly\Cqrs\Exception\CommandProcessingException;
use Firefly\Kernel\Exception\Security\AuthenticationException;
use Firefly\Kernel\Exception\Security\AuthorizationException;
use Firefly\Security\Tests\Fixtures\Advice\ArchiveCommand;
use Firefly\Security\Tests\Fixtures\Advice\ReportService;
use Firefly\Security\Tests\Support\SecurityCapstoneTestCase;
use Firefly\Security\Tests\Support\SecurityFlows;
use Illuminate\Support\Facades\Route;

/**
 * The test-support surface against the REAL pipeline: URL rules, the dispatcher guard, a proxied #[Service]
 * and the CQRS bus authorizer all see the acting principal, and withoutSecurity() disarms all four.
 */
abstract class TestSupportCapstoneTestCase extends SecurityCapstoneTestCase
{
    protected function fixturePaths(): array
    {
        return [
            ...parent::fixturePaths(),
            'Firefly\\Security\\Tests\\Fixtures\\Advice\\' => dirname(__DIR__).'/Fixtures/Advice',
        ];
    }

    protected function securityOverrides(): array
    {
        return [
            'firefly.security.form_login.enabled' => true,
            'firefly.security.http.rules' => [
                ['pattern' => 'open', 'access' => 'permitAll'],
                ['pattern' => 'open/*', 'access' => 'permitAll'],
                ['pattern' => 'admin/*', 'access' => 'hasRole:ADMIN'],
                ['pattern' => 'advice/*', 'access' => 'authenticated'],
                ['pattern' => '*', 'access' => 'authenticated'],
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/admin/panel', static fn (): array => ['admin' => true]);
    }
}

uses(TestSupportCapstoneTestCase::class, SecurityFlows::class);

it('acts as a principal through URL rules, the dispatcher guard and the proxied service', function () {
    /** @var TestSupportCapstoneTestCase $this */
    $this->actingAsPrincipal('ada', ['ROLE_USER']);

    $this->getJson('/whoami')->assertOk()->assertJson(['name' => 'ada', 'authorities' => ['ROLE_USER']]);
    $this->getJson('/admin/panel')->assertStatus(403);
    $this->getJson('/advice/reports')->assertStatus(403)->assertJson(['code' => 'ACCESS_DENIED', 'requiredAuthorities' => ['ROLE_ADMIN']]);

    /** @var ReportService $service */
    $service = $this->fireflyContext()->get(ReportService::class);
    expect($service::class)->not->toBe(ReportService::class)
        ->and(fn () => $service->totals())->toThrow(AuthorizationException::class)
        ->and($this->events->denials())->toHaveCount(3);

    $this->actingAsPrincipal('root', ['ROLE_ADMIN']);

    $this->getJson('/admin/panel')->assertOk();
    $this->getJson('/advice/reports')->assertOk();
    expect($service->totals())->toBe(['total' => 42]);
});

it('disarms URL security, the dispatcher guard and the proxy link with withoutSecurity()', function () {
    /** @var TestSupportCapstoneTestCase $this */
    $this->getJson('/admin/panel')->assertStatus(401);

    $this->withoutSecurity();

    $this->getJson('/admin/panel')->assertOk();
    $this->getJson('/advice/reports')->assertOk();

    /** @var ReportService $service */
    $service = $this->fireflyContext()->get(ReportService::class);
    expect($service->totals())->toBe(['total' => 42]);
});

it('disarms the CQRS bus authorizer with withoutSecurity(), so a secured command dispatches', function () {
    /** @var TestSupportCapstoneTestCase $this */
    /** @var CommandBus $commands */
    $commands = $this->fireflyContext()->get(CommandBus::class);

    // The bus holds its authorizer by constructor and the enforcer is gated only at boot, so this is the seam a
    // config flip after boot could NOT reach: it has to read the flags live, the way the proxy link does.
    try {
        $commands->send(new ArchiveCommand(1));
        throw new LogicException('not refused');
    } catch (CommandProcessingException $e) {
        expect($e->getPrevious())->toBeInstanceOf(AuthenticationException::class);
    }

    $this->actingAsPrincipal('ada', ['ROLE_USER']);

    try {
        $commands->send(new ArchiveCommand(1));
        throw new LogicException('not refused');
    } catch (CommandProcessingException $e) {
        expect($e->getPrevious())->toBeInstanceOf(AuthorizationException::class);
    }

    $this->withoutSecurity();

    expect($commands->send(new ArchiveCommand(1)))->toBe('archived:1');
});

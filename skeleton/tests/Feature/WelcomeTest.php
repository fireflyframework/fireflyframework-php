<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The skeleton shipped no test suite at all, while its README referenced a tests/ directory and a Pest
 * plugin that did not exist. These two cases are the smoke test a new application should start from: the
 * HTML stereotype renders, and the JSON stereotype negotiates — the difference between #[Controller] and
 * #[RestController].
 */
final class WelcomeTest extends TestCase
{
    public function test_the_welcome_page_renders_html(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $response->assertSee('Hello,', false);
        $response->assertSee('Your paths', false);
    }

    public function test_the_sample_rest_controller_returns_json(): void
    {
        $this->getJson('/greetings/Ada')
            ->assertOk()
            ->assertExactJson(['message' => 'Hello, Ada!']);
    }

    public function test_the_actuator_reports_health(): void
    {
        $this->getJson('/actuator/health')
            ->assertOk()
            ->assertJsonPath('status', 'UP');
    }
}

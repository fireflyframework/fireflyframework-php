<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The skeleton shipped no test suite at all, while its README referenced a tests/ directory and a Pest
 * plugin that did not exist. These cases are the smoke test a new application should start from: the HTML
 * stereotype renders, and the JSON stereotype negotiates — the difference between #[Controller] and
 * #[RestController]. The sample REST resource has its own file, OrderTest.
 */
final class WelcomeTest extends TestCase
{
    public function test_the_welcome_page_renders_html(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $response->assertSee('Hello,', false);
        $response->assertSee('Your routes', false);
        $response->assertSee('What is running', false);
    }

    public function test_the_sample_rest_controller_returns_json(): void
    {
        $this->getJson('/greetings/Ada')
            ->assertOk()
            ->assertExactJson(['message' => 'Hello, Ada!']);
    }

    public function test_the_welcome_page_lists_the_sample_resource(): void
    {
        // The page enumerates the RouteManifest the dispatcher itself reads, so this is a check that the
        // sample resource is genuinely compiled and routable — not that a string was hard-coded in a view.
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('/orders', false);
        $response->assertSee('/orders/{id}', false);
        $response->assertSee('OrderController', false);
    }

    public function test_the_actuator_reports_health(): void
    {
        $this->getJson('/actuator/health')
            ->assertOk()
            ->assertJsonPath('status', 'UP');
    }
}

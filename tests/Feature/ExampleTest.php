<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_health_check_is_public_and_has_no_content(): void
    {
        $this->get('/up')->assertNoContent();
    }

    public function test_api_health_check_is_public_and_has_no_content(): void
    {
        $this->get('/api/health')->assertNoContent();
    }
}

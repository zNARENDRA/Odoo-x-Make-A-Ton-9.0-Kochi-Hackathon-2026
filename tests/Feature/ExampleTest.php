<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_home_opens_login_for_guests(): void
    {
        $this->get('/')->assertRedirectToRoute('login');
    }

    public function test_home_opens_dashboard_for_signed_in_users(): void
    {
        $this->actingAs(User::factory()->make())->get('/')->assertRedirectToRoute('dashboard');
    }
}

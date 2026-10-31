<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_starter_kit_authentication_pages_render(): void
    {
        $this->get('/login')->assertOk()->assertSee('Log in to your account');
        $this->get('/register')->assertOk()->assertSee('Create an account');
        $this->get('/forgot-password')->assertOk();
    }

    public function test_registration_normalizes_email_and_cannot_assign_privileged_roles(): void
    {
        $this->post('/register', ['name' => 'Customer', 'email' => 'NEW@example.test', 'password' => 'StrongPass!', 'password_confirmation' => 'StrongPass!', 'role' => 'admin'])->assertRedirect('/dashboard');
        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame('customer', $user->role);
        $this->assertTrue(Hash::check('StrongPass!', $user->password));
        $this->post('/logout');
        $this->post('/register', ['name' => 'Duplicate', 'email' => 'NEW@example.test', 'password' => 'StrongPass!', 'password_confirmation' => 'StrongPass!'])->assertSessionHasErrors('email');
    }

    public static function weakPasswords(): array
    {
        return ['too short' => ['Abcdef!h'], 'no uppercase' => ['abcdefghi!'], 'no lowercase' => ['ABCDEFGHI!'], 'no symbol' => ['Abcdefghi9']];
    }

    #[DataProvider('weakPasswords')]
    public function test_registration_rejects_weak_passwords(string $password): void
    {
        $this->post('/register', ['name' => 'Customer', 'email' => 'new@example.test', 'password' => $password, 'password_confirmation' => $password])->assertSessionHasErrors('password');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_login_is_throttled_after_repeated_failures(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => 'missing@example.test', 'password' => 'Wrong!Password'])->assertSessionHasErrors('email');
        }
        $this->postJson('/login', ['email' => 'missing@example.test', 'password' => 'Wrong!Password'])->assertTooManyRequests();
    }

    public function test_password_reset_sends_notification_and_accepts_a_strong_replacement(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');
        Notification::assertSentTo($user, ResetPassword::class);
        $token = Password::createToken($user);
        $this->get('/reset-password/'.$token.'?email='.urlencode($user->email))->assertOk();
        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'NewStrong!Password', 'password_confirmation' => 'NewStrong!Password'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('NewStrong!Password', $user->fresh()->password));
    }
}

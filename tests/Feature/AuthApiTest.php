<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visitor_can_register_and_receive_a_portal_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Portal User',
            'email' => 'portal@example.com',
            'password' => 'securePassword123',
            'password_confirmation' => 'securePassword123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.email', 'portal@example.com')
            ->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email'], 'token']]);

        $this->assertDatabaseHas('users', ['email' => 'portal@example.com']);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_a_registered_user_can_log_in_and_log_out(): void
    {
        $user = User::factory()->create([
            'email' => 'member@example.com',
            'password' => 'securePassword123',
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'member@example.com',
            'password' => 'securePassword123',
        ]);

        $login->assertOk()->assertJsonPath('data.user.id', $user->id);
        $token = $login->json('data.token');

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'member@example.com');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_invalid_credentials_return_422_without_authenticating_a_user(): void
    {
        User::factory()->create([
            'email' => 'member@example.com',
            'password' => 'securePassword123',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'member@example.com',
            'password' => 'wrongPassword123',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_private_endpoints_return_401_without_authentication(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
        $this->getJson('/api/v1/integrations/linkedin')->assertUnauthorized();
    }
}

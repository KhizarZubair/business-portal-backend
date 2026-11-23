<?php

namespace Tests\Feature;

use App\Models\ConnectedAccount;
use App\Models\LinkedInOAuthState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LinkedInOAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_oauth_redirect_uses_oidc_scopes_and_persists_only_a_hash_of_state(): void
    {
        $redirectUri = 'https://portal.example.test/api/v1/integrations/linkedin/callback';

        config([
            'services.linkedin.client_id' => 'test-client-id',
            'services.linkedin.client_secret' => 'test-client-secret',
            'services.linkedin.redirect_uri' => $redirectUri,
            'services.linkedin.scopes' => ['openid', 'profile', 'email'],
            'app.env' => 'production',
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/integrations/linkedin/redirect');
        $response->assertOk();

        $authorizationUrl = $response->json('data.authorization_url');
        parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);

        $this->assertSame('test-client-id', $query['client_id']);
        $this->assertSame($redirectUri, $query['redirect_uri']);
        $this->assertSame('openid profile email', $query['scope']);
        $this->assertDatabaseHas('linkedin_oauth_states', [
            'user_id' => $user->id,
            'state_hash' => hash('sha256', $query['state']),
        ]);
        $this->assertDatabaseMissing('linkedin_oauth_states', [
            'state_hash' => $query['state'],
        ]);
    }

    public function test_callback_stores_linkedin_identity_and_encrypts_the_access_token(): void
    {
        $redirectUri = 'https://portal.example.test/api/v1/integrations/linkedin/callback';

        config([
            'services.linkedin.client_id' => 'test-client-id',
            'services.linkedin.client_secret' => 'test-client-secret',
            'services.linkedin.redirect_uri' => $redirectUri,
            'services.linkedin.frontend_url' => 'http://localhost:5173',
            'services.linkedin.scopes' => ['openid', 'profile', 'email'],
        ]);

        $user = User::factory()->create();
        $state = Str::random(64);

        LinkedInOAuthState::query()->create([
            'user_id' => $user->id,
            'state_hash' => hash('sha256', $state),
            'expires_at' => now()->addMinutes(10),
        ]);

        Http::preventStrayRequests();

        Http::fake([
            'https://www.linkedin.com/oauth/v2/accessToken' => Http::response([
                'access_token' => 'linkedin-secret-token',
                'expires_in' => 3600,
                'scope' => 'openid profile email',
            ]),
            'https://api.linkedin.com/v2/userinfo' => Http::response([
                'sub' => 'linkedin-member-123',
                'name' => 'LinkedIn Member',
                'email' => 'member@linkedin.example',
                'picture' => 'https://example.test/profile.jpg',
                'email_verified' => true,
            ]),
        ]);

        $response = $this->get('/api/v1/integrations/linkedin/callback?'.http_build_query([
            'state' => $state,
            'code' => 'authorization-code',
        ]));

        $response->assertRedirect('http://localhost:5173/integrations/linkedin?linkedin=connected');

        $account = ConnectedAccount::query()->firstOrFail();
        $this->assertSame($user->id, $account->user_id);
        $this->assertSame('LinkedIn Member', $account->display_name);
        $this->assertSame('linkedin-secret-token', $account->access_token);
        $this->assertNotSame('linkedin-secret-token', DB::table('connected_accounts')->value('access_token'));
        $this->assertNotNull(LinkedInOAuthState::query()->firstOrFail()->used_at);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://www.linkedin.com/oauth/v2/accessToken'
            && $request['redirect_uri'] === $redirectUri);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.linkedin.com/v2/userinfo' && $request->hasHeader('Authorization', 'Bearer linkedin-secret-token'));
    }

    public function test_linkedin_redirect_reports_missing_app_credentials_without_starting_oauth(): void
    {
        config([
            'services.linkedin.client_id' => null,
            'services.linkedin.client_secret' => null,
            'services.linkedin.redirect_uri' => 'https://portal.example.test/api/v1/integrations/linkedin/callback',
        ]);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/integrations/linkedin/redirect')
            ->assertStatus(503)
            ->assertJsonPath('message', 'LinkedIn OAuth is not configured. Add the app credentials, callback URL and scopes to the backend environment.');
    }

    public function test_oauth_redirect_allows_http_loopback_callback_urls_in_local_environment(): void
    {
        config([
            'services.linkedin.client_id' => 'test-client-id',
            'services.linkedin.client_secret' => 'test-client-secret',
            'services.linkedin.scopes' => ['openid', 'profile', 'email'],
            'app.env' => 'local',
        ]);

        Sanctum::actingAs(User::factory()->create());

        foreach ([
            'http://localhost:8000/api/v1/integrations/linkedin/callback',
            'http://127.0.0.1:8000/api/v1/integrations/linkedin/callback',
        ] as $redirectUri) {
            config(['services.linkedin.redirect_uri' => $redirectUri]);

            $response = $this->getJson('/api/v1/integrations/linkedin/redirect');
            $response->assertOk();

            parse_str((string) parse_url($response->json('data.authorization_url'), PHP_URL_QUERY), $query);
            $this->assertSame($redirectUri, $query['redirect_uri']);
        }
    }

    public function test_oauth_redirect_rejects_http_loopback_callback_urls_in_production(): void
    {
        config([
            'services.linkedin.client_id' => 'test-client-id',
            'services.linkedin.client_secret' => 'test-client-secret',
            'services.linkedin.redirect_uri' => 'http://localhost:8000/api/v1/integrations/linkedin/callback',
            'services.linkedin.scopes' => ['openid', 'profile', 'email'],
            'app.env' => 'production',
        ]);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/integrations/linkedin/redirect')
            ->assertStatus(503)
            ->assertJsonPath('message', 'LinkedIn OAuth requires an HTTPS callback URL, except for localhost and 127.0.0.1 when APP_ENV=local. Register the exact callback URL in the LinkedIn Developer Portal and backend environment.');
    }

    public function test_oauth_redirect_rejects_non_loopback_http_callback_urls_in_local_environment(): void
    {
        config([
            'services.linkedin.client_id' => 'test-client-id',
            'services.linkedin.client_secret' => 'test-client-secret',
            'services.linkedin.redirect_uri' => 'http://portal.example.test/api/v1/integrations/linkedin/callback',
            'services.linkedin.scopes' => ['openid', 'profile', 'email'],
            'app.env' => 'local',
        ]);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/integrations/linkedin/redirect')
            ->assertStatus(503);
    }

    public function test_linkedin_status_exposes_setup_instructions_without_exposing_credentials(): void
    {
        config([
            'services.linkedin.client_id' => null,
            'services.linkedin.client_secret' => null,
            'services.linkedin.redirect_uri' => 'http://localhost:8000/api/v1/integrations/linkedin/callback',
            'services.linkedin.scopes' => ['openid', 'profile', 'email'],
            'app.env' => 'local',
        ]);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/integrations/linkedin')
            ->assertOk()
            ->assertJsonPath('data.setup.credentials_configured', false)
            ->assertJsonPath('data.setup.callback_url', 'http://localhost:8000/api/v1/integrations/linkedin/callback')
            ->assertJsonPath('data.setup.callback_uses_https', false)
            ->assertJsonPath('data.setup.callback_allowed_for_environment', true)
            ->assertJsonPath('data.setup.scopes.0', 'openid')
            ->assertJsonMissingPath('data.setup.client_secret');
    }

    public function test_linkedin_accounts_are_scoped_to_the_authenticated_portal_user(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        ConnectedAccount::query()->create([
            'user_id' => $owner->id,
            'provider' => 'linkedin',
            'provider_account_id' => 'owner-member',
            'account_type' => 'member',
            'display_name' => 'Owner LinkedIn',
            'access_token' => 'encrypted-token',
            'scopes' => ['openid', 'profile'],
            'status' => 'connected',
        ]);

        Sanctum::actingAs($otherUser);

        $this->getJson('/api/v1/integrations/linkedin')
            ->assertOk()
            ->assertJsonCount(0, 'data.accounts')
            ->assertJsonMissingPath('data.accounts.0.access_token');
    }
}

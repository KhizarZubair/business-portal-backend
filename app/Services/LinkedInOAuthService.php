<?php

namespace App\Services;

use App\Models\ConnectedAccount;
use App\Models\LinkedInOAuthState;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class LinkedInOAuthService
{
    public function authorizationUrl(User $user): string
    {
        $clientId = (string) config('services.linkedin.client_id');
        $clientSecret = (string) config('services.linkedin.client_secret');
        $redirectUri = (string) config('services.linkedin.redirect_uri');
        $scopes = config('services.linkedin.scopes', ['openid', 'profile', 'email']);

        if ($clientId === '' || $clientSecret === '' || $redirectUri === '' || $scopes === []) {
            throw new RuntimeException('LinkedIn OAuth is not configured. Add the app credentials, callback URL and scopes to the backend environment.');
        }

        if (! str_starts_with($redirectUri, 'https://')) {
            throw new RuntimeException('LinkedIn OAuth requires a publicly reachable HTTPS callback URL. Register the same URL in the LinkedIn Developer Portal and backend environment.');
        }

        $state = Str::random(64);

        LinkedInOAuthState::query()->create([
            'user_id' => $user->id,
            'state_hash' => hash('sha256', $state),
            'expires_at' => now()->addMinutes(10),
        ]);

        return 'https://www.linkedin.com/oauth/v2/authorization?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => implode(' ', $scopes),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function completeAuthorization(string $state, string $code): ConnectedAccount
    {
        $oauthState = $this->consumeState($state);

        $tokenResponse = Http::asForm()
            ->acceptJson()
            ->timeout(15)
            ->post('https://www.linkedin.com/oauth/v2/accessToken', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => config('services.linkedin.client_id'),
                'client_secret' => config('services.linkedin.client_secret'),
                'redirect_uri' => config('services.linkedin.redirect_uri'),
            ])
            ->throw();

        $tokenData = $tokenResponse->json();
        $accessToken = data_get($tokenData, 'access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('LinkedIn did not return an access token.');
        }

        $profileResponse = Http::acceptJson()
            ->withToken($accessToken)
            ->timeout(15)
            ->get('https://api.linkedin.com/v2/userinfo')
            ->throw();

        $profile = $profileResponse->json();
        $providerAccountId = data_get($profile, 'sub');

        if (! is_string($providerAccountId) || $providerAccountId === '') {
            throw new RuntimeException('LinkedIn did not return a member identifier.');
        }

        $scopeValue = data_get($tokenData, 'scope');
        $scopes = is_string($scopeValue) && trim($scopeValue) !== ''
            ? preg_split('/\s+/', trim($scopeValue))
            : config('services.linkedin.scopes', ['openid', 'profile', 'email']);

        return DB::transaction(function () use ($oauthState, $tokenData, $accessToken, $profile, $providerAccountId, $scopes): ConnectedAccount {
            return ConnectedAccount::query()->updateOrCreate(
                [
                    'user_id' => $oauthState->user_id,
                    'provider' => 'linkedin',
                ],
                [
                    'provider_account_id' => $providerAccountId,
                    'account_type' => 'member',
                    'display_name' => data_get($profile, 'name'),
                    'email' => data_get($profile, 'email'),
                    'picture_url' => data_get($profile, 'picture'),
                    'access_token' => $accessToken,
                    'refresh_token' => data_get($tokenData, 'refresh_token'),
                    'token_expires_at' => now()->addSeconds((int) data_get($tokenData, 'expires_in', 0)),
                    'scopes' => array_values(array_filter($scopes ?: [])),
                    'status' => 'connected',
                    'metadata' => [
                        'given_name' => data_get($profile, 'given_name'),
                        'family_name' => data_get($profile, 'family_name'),
                        'locale' => data_get($profile, 'locale'),
                        'email_verified' => data_get($profile, 'email_verified'),
                    ],
                    'last_synced_at' => now(),
                ]
            );
        });
    }

    public function discardState(?string $state): void
    {
        if (! is_string($state) || $state === '') {
            return;
        }

        try {
            $this->consumeState($state);
        } catch (InvalidArgumentException) {
            // Invalid or expired state is intentionally ignored on provider cancellation.
        }
    }

    private function consumeState(string $state): LinkedInOAuthState
    {
        return DB::transaction(function () use ($state): LinkedInOAuthState {
            $oauthState = LinkedInOAuthState::query()
                ->where('state_hash', hash('sha256', $state))
                ->lockForUpdate()
                ->first();

            if (
                $oauthState === null
                || $oauthState->used_at !== null
                || $oauthState->expires_at->isPast()
            ) {
                throw new InvalidArgumentException('LinkedIn authorization state is invalid or expired.');
            }

            $oauthState->forceFill(['used_at' => now()])->save();

            return $oauthState;
        });
    }
}

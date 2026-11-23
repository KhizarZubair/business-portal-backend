<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConnectedAccountResource;
use App\Models\User;
use App\Services\LinkedInOAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class LinkedInOAuthController extends Controller
{
    public function redirect(Request $request, LinkedInOAuthService $service): JsonResponse
    {
        try {
            $authorizationUrl = $service->authorizationUrl($request->user());
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 503);
        }

        return response()->json([
            'data' => [
                'authorization_url' => $authorizationUrl,
            ],
        ]);
    }

    public function callback(Request $request, LinkedInOAuthService $service): RedirectResponse
    {
        $frontendUrl = rtrim((string) config('services.linkedin.frontend_url'), '/');

        if ($request->filled('error')) {
            $service->discardState($request->query('state'));

            return redirect()->away($frontendUrl.'/integrations/linkedin?linkedin=cancelled');
        }

        try {
            $state = $request->query('state');
            $code = $request->query('code');

            if (! is_string($state) || ! is_string($code) || $state === '' || $code === '') {
                throw new RuntimeException('LinkedIn callback is missing required parameters.');
            }

            $service->completeAuthorization($state, $code);

            return redirect()->away($frontendUrl.'/integrations/linkedin?linkedin=connected');
        } catch (Throwable $exception) {
            Log::warning('LinkedIn OAuth callback failed.', [
                'exception' => $exception::class,
            ]);

            return redirect()->away($frontendUrl.'/integrations/linkedin?linkedin=failed');
        }
    }

    public function show(Request $request, LinkedInOAuthService $service): JsonResponse
    {
        $accounts = $request->user()
            ->connectedAccounts()
            ->where('provider', 'linkedin')
            ->where('status', 'connected')
            ->latest()
            ->get();

        return response()->json([
            'data' => [
                'accounts' => ConnectedAccountResource::collection($accounts),
                'setup' => [
                    'credentials_configured' => filled(config('services.linkedin.client_id'))
                        && filled(config('services.linkedin.client_secret'))
                        && filled(config('services.linkedin.redirect_uri')),
                    'callback_url' => (string) config('services.linkedin.redirect_uri'),
                    'callback_uses_https' => str_starts_with((string) config('services.linkedin.redirect_uri'), 'https://'),
                    'callback_allowed_for_environment' => $service->isCallbackUrlAllowedForCurrentEnvironment((string) config('services.linkedin.redirect_uri')),
                    'scopes' => config('services.linkedin.scopes', []),
                ],
                'capabilities' => [
                    'personal_member_profile' => true,
                    'company_pages' => false,
                    'company_pages_reason' => 'Company Page access requires LinkedIn organization permissions and API product approval. The current connection only requests OpenID Connect member permissions.',
                    'publishing' => false,
                    'publishing_reason' => 'Share on LinkedIn permission has not been configured for this connection.',
                ],
            ],
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->connectedAccounts()
            ->where('provider', 'linkedin')
            ->delete();

        return response()->json([
            'message' => 'LinkedIn was disconnected from this workspace. You can also revoke the app from LinkedIn settings.',
        ]);
    }
}

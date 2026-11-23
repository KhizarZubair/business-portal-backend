<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConnectedAccountResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $account = $request->user()
            ->connectedAccounts()
            ->where('provider', 'linkedin')
            ->where('status', 'connected')
            ->latest()
            ->first();

        return response()->json([
            'data' => [
                'linkedin' => [
                    'connected' => $account !== null,
                    'account' => $account === null ? null : ConnectedAccountResource::make($account),
                    'granted_permissions' => $account?->scopes ?? [],
                    'account_type' => $account?->account_type,
                    'capabilities' => [
                        'personal_member_profile' => true,
                        'company_pages' => false,
                        'company_pages_reason' => 'Company Page access requires LinkedIn organization permissions and API product approval.',
                        'publishing' => false,
                        'publishing_reason' => 'Share on LinkedIn permission has not been configured for this connection.',
                    ],
                ],
            ],
        ]);
    }
}

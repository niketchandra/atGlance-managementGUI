<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1 discovery and identity for the atglance CLI v2, for user and service account keys.
 */
class MetaController extends Controller
{
    public function meta(): JsonResponse
    {
        return response()->json([
            'api' => 'atglance',
            'api_version' => 1,
            'console_version' => config('app.version'),
        ]);
    }

    public function whoami(Request $request): JsonResponse
    {
        $identity = ApiIdentity::current($request);
        $token = $identity->token;
        $tokenInfo = [
            'id' => $token->id,
            'name' => $token->name,
            'expires_at' => $token->expires_at?->toIso8601String(),
        ];

        if ($identity->isServiceAccount()) {
            $account = $identity->serviceAccount;

            return response()->json([
                'identity_type' => 'service_account',
                'service_account' => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'scope' => $account->scope,
                    'org_id' => (int) $account->org_id,
                ],
                'workspaces' => $account->grants()
                    ->where('status', 'active')
                    ->with('workspace:id,name')
                    ->orderBy('workspace_id')
                    ->get()
                    ->map(fn ($grant) => [
                        'id' => (int) $grant->workspace_id,
                        'name' => $grant->workspace?->name,
                        'permissions' => array_values((array) $grant->permissions),
                    ])
                    ->values(),
                'token' => $tokenInfo,
            ]);
        }

        $user = $identity->user;

        return response()->json([
            'identity_type' => 'user',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'status' => $user->status ?? 'active',
                'org_id' => $user->org_id,
            ],
            'token' => $tokenInfo,
        ]);
    }
}

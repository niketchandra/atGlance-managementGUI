<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/v1 discovery and identity for the atglance CLI v2.
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
        $user = $request->user();
        $token = $request->attributes->get('patToken');

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'status' => $user->status ?? 'active',
                'org_id' => $user->org_id,
            ],
            'token' => [
                'id' => $token?->id,
                'name' => $token?->name,
                'expires_at' => $token?->expires_at?->toIso8601String(),
            ],
        ]);
    }
}

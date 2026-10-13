<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PatToken;
use App\Models\User;
use App\Services\ServiceAccountException;
use App\Services\UserKeys;
use App\Support\License;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PatTokenController extends Controller
{
    public function index(Request $request)
    {
        $tokens = PatToken::query()
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $request->user()->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'message' => 'Keys are shown only when created.',
            'tokens' => $tokens->map(fn (PatToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
                'last_four' => $token->last_four,
                'status' => $token->status,
                'abilities' => $token->abilities,
                'expires_at' => $token->expires_at,
                'last_used_at' => $token->last_used_at,
                'revoked_at' => $token->revoked_at,
                'created_at' => $token->created_at,
            ]),
        ]);
    }

    public function store(Request $request)
    {
        if (!License::isActive()) {
            return response()->json(['message' => License::REQUIRED_MESSAGE], 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['sometimes', 'array'],
            'abilities.*' => ['string', 'max:100'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $user = $request->user();
        $requestedExpiry = !empty($data['expires_at']) ? Carbon::parse($data['expires_at']) : null;

        // abilities is still accepted but ignored: every key gets ['*'] until scopes land.
        try {
            ['token' => $token, 'plain' => $plainToken] = app(UserKeys::class)->issue($user, $data['name'], $requestedExpiry);
        } catch (ServiceAccountException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 422);
        }

        return response()->json([
            'access_token' => $plainToken,
            'token_type' => 'pat',
            'message' => 'Save this key securely: it will not be shown again. It expires on ' . $token->expires_at->toDateString() . '.',
            'token' => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'expires_at' => $token->expires_at,
                'created_at' => $token->created_at,
            ],
        ], 201);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PatToken;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PatTokenController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $tokens = PatToken::where('user_id', $user->id)->get();

        return response()->json([
            'message' => 'PAT tokens are permanent. Plain text tokens are only shown when created.',
            'tokens' => $tokens->map(function ($token) {
                return [
                    'id' => $token->id,
                    'name' => $token->name,
                    'token_hash' => $token->token,
                    'abilities' => $token->abilities,
                    'expires_at' => $token->expires_at,
                    'last_used_at' => $token->last_used_at,
                    'created_at' => $token->created_at,
                ];
            }),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['sometimes', 'array'],
            'abilities.*' => ['string', 'max:100'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $user = $request->user();
        $abilities = $data['abilities'] ?? ['*'];
        $expiresAt = array_key_exists('expires_at', $data) && $data['expires_at'] !== null
            ? Carbon::parse($data['expires_at'])
            : Carbon::create(2099, 12, 31, 23, 59, 59); // Default to 2099

        // Generate custom PAT token with "atgla-" prefix
        $plainToken = PatToken::generateCustomToken();
        $hashedToken = hash('sha256', $plainToken);

        // Create PAT token record
        $token = PatToken::create([
            'user_id' => $user->id,
            'tokenable_type' => get_class($user),
            'tokenable_id' => $user->id,
            'name' => $data['name'],
            'token' => $hashedToken,
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
        ]);

        return response()->json([
            'access_token' => $plainToken,
            'token_type' => 'pat',
            'message' => 'PAT token is permanent. Save it securely - it won\'t be shown again.',
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

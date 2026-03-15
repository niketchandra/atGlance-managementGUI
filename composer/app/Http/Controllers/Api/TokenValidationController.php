<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PatToken;
use App\Models\User;
use Illuminate\Http\Request;

class TokenValidationController extends Controller
{
    /**
     * Validate a PAT token
     * Returns user info if token is valid and user is active
     */
    public function validateToken(Request $request)
    {
        $request->validate([
            'token' => ['required', 'string'],
        ]);

        $token = $request->input('token');

        // Hash the provided token
        $hashedToken = hash('sha256', $token);

        // Find the PAT token in database
        $patToken = PatToken::where('token', $hashedToken)->first();

        if (!$patToken) {
            return response()->json([
                'message' => 'Invalid token',
                'is_valid' => false,
            ], 401);
        }

        // Check token status (active/revoked)
        if (($patToken->status ?? 'active') !== 'active') {
            return response()->json([
                'message' => 'Token is revoked or inactive',
                'is_valid' => false,
                'token_status' => $patToken->status,
            ], 401);
        }

        // Check if token has expired
        if ($patToken->expires_at && $patToken->expires_at->isPast()) {
            return response()->json([
                'message' => 'Token has expired',
                'is_valid' => false,
                'expired_at' => $patToken->expires_at,
            ], 401);
        }

        // Get the associated user
        $user = User::find($patToken->user_id);

        if (!$user) {
            return response()->json([
                'message' => 'User not found',
                'is_valid' => false,
            ], 404);
        }

        // Check if user is active
        if ($user->status !== 'active') {
            return response()->json([
                'message' => 'User is not active',
                'is_valid' => false,
                'user_status' => $user->status,
            ], 403);
        }

        // Update last_used_at timestamp
        $patToken->touch('last_used_at');

        // Token is valid, return user info
        return response()->json([
            'message' => 'Token is valid',
            'is_valid' => true,
            'user' => [
                'id' => $user->id,
                'org_id' => $user->org_id,
                'name' => $user->name,
                'email' => $user->email,
                'dob' => $user->dob,
                'status' => $user->status,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ],
            'token_info' => [
                'id' => $patToken->id,
                'name' => $patToken->name,
                'abilities' => $patToken->abilities,
                'expires_at' => $patToken->expires_at,
                'last_used_at' => $patToken->last_used_at,
                'created_at' => $patToken->created_at,
            ],
        ], 200);
    }

    /**
     * Validate token from Authorization header
     * Returns user info if token is valid and user is active
     */
    public function validateFromHeader(Request $request)
    {
        // Extract token from Authorization header
        $authHeader = $request->header('Authorization');

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return response()->json([
                'message' => 'Missing or invalid Authorization header',
                'is_valid' => false,
            ], 400);
        }

        $token = substr($authHeader, 7); // Remove "Bearer " prefix

        // Hash the provided token
        $hashedToken = hash('sha256', $token);

        // Find the PAT token in database
        $patToken = PatToken::where('token', $hashedToken)->first();

        if (!$patToken) {
            return response()->json([
                'message' => 'Invalid token',
                'is_valid' => false,
            ], 401);
        }

        // Check token status (active/revoked)
        if (($patToken->status ?? 'active') !== 'active') {
            return response()->json([
                'message' => 'Token is revoked or inactive',
                'is_valid' => false,
                'token_status' => $patToken->status,
            ], 401);
        }

        // Check if token has expired
        if ($patToken->expires_at && $patToken->expires_at->isPast()) {
            return response()->json([
                'message' => 'Token has expired',
                'is_valid' => false,
                'expired_at' => $patToken->expires_at,
            ], 401);
        }

        // Get the associated user
        $user = User::find($patToken->user_id);

        if (!$user) {
            return response()->json([
                'message' => 'User not found',
                'is_valid' => false,
            ], 404);
        }

        // Check if user is active
        if ($user->status !== 'active') {
            return response()->json([
                'message' => 'User is not active',
                'is_valid' => false,
                'user_status' => $user->status,
            ], 403);
        }

        // Update last_used_at timestamp
        $patToken->touch('last_used_at');

        // Token is valid, return user info
        return response()->json([
            'message' => 'Token is valid',
            'is_valid' => true,
            'user' => [
                'id' => $user->id,
                'org_id' => $user->org_id,
                'name' => $user->name,
                'email' => $user->email,
                'dob' => $user->dob,
                'status' => $user->status,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ],
            'token_info' => [
                'id' => $patToken->id,
                'name' => $patToken->name,
                'abilities' => $patToken->abilities,
                'expires_at' => $patToken->expires_at,
                'last_used_at' => $patToken->last_used_at,
                'created_at' => $patToken->created_at,
            ],
        ], 200);
    }
}

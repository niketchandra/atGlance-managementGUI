<?php

namespace App\Http\Middleware;

use App\Models\PatToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatePatToken
{
    /**
     * Handle an incoming request - authenticate using PAT tokens only
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['message' => 'PAT token required'], 401);
        }

        // Check if it's a PAT token (starts with "atgla-")
        if (!str_starts_with($token, 'atgla-')) {
            return response()->json(['message' => 'Invalid token format. PAT token required (atgla-...)'], 403);
        }

        $hashedToken = hash('sha256', $token);
        $patToken = PatToken::where('token', $hashedToken)->first();

        if (!$patToken) {
            return response()->json(['message' => 'Invalid PAT token'], 401);
        }

        if (($patToken->status ?? 'active') !== 'active') {
            return response()->json(['message' => 'PAT token is revoked or inactive'], 401);
        }

        // Check expiration
        if ($patToken->expires_at && $patToken->expires_at->isPast()) {
            return response()->json(['message' => 'PAT token has expired'], 401);
        }

        // Update last used timestamp
        $patToken->update(['last_used_at' => now()]);

        // Set the authenticated user
        $request->setUserResolver(function () use ($patToken) {
            return $patToken->user;
        });

        $request->attributes->set('patToken', $patToken);

        return $next($request);
    }
}

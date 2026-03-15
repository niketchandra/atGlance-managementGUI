<?php

namespace App\Http\Middleware;

use App\Models\Session;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSession
{
    /**
     * Handle an incoming request - authenticate using sessions table
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $hashedToken = hash('sha256', $token);
        $session = Session::where('token', $hashedToken)->first();

        if (!$session || $session->isExpired()) {
            return response()->json(['message' => 'Invalid or expired session token'], 401);
        }

        // Update last used timestamp
        $session->update(['last_used_at' => now()]);

        // Set the authenticated user
        $request->setUserResolver(function () use ($session) {
            return $session->user;
        });

        return $next($request);
    }
}

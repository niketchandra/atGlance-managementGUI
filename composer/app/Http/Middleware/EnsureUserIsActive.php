<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user && strtolower((string) ($user->status ?? 'active')) !== 'active') {
            // API calls (PAT, no session) get JSON instead of a logout redirect.
            if (!$request->hasSession()) {
                return response()->json(['message' => 'User is inactive'], 403);
            }

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('home')
                ->with('inactive_user', 'User is Inactive please reachout to the Administrator');
        }

        return $next($request);
    }
}

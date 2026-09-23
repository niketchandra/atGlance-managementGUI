<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureProfileSetupComplete
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        if (!Auth::check()) {
            return $next($request);
        }

        $user = Auth::user();
        $hasDob = !empty($user->dob);
        $hasPin = !empty($user->pin);

        if ($hasDob && $hasPin) {
            return $next($request);
        }

        if ($request->routeIs('profile') || $request->routeIs('profile.update')) {
            return $next($request);
        }

        return redirect()->route('profile')->with('complete_profile_required', 'Please set your Date of Birth and 5-digit PIN before continuing.');
    }
}

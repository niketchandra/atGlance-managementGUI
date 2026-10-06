<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminRoleMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        if (!Auth::check()) {
            return redirect()->route('login.form');
        }

        $rbacId = (int) Auth::user()->rbac_id;

        if (!in_array($rbacId, [100, 101], true)) {
            return redirect()->route('dashboard')->withErrors([
                'authorization' => 'You do not have permission to access admin dashboard.',
            ]);
        }

        return $next($request);
    }
}

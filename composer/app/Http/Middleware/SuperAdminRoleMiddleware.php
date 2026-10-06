<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SuperAdminRoleMiddleware
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

        if ($rbacId !== 100) {
            return redirect()->route('dashboard')->withErrors([
                'authorization' => 'Only super admin users can access enterprise console.',
            ]);
        }

        return $next($request);
    }
}

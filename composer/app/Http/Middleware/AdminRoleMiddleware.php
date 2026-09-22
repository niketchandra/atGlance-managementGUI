<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminRoleMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (!Auth::check()) {
            return redirect()->route('login.form');
        }

        if (!in_array((int) Auth::user()->rbac_id, [100, 101], true)) {
            return redirect()->route('dashboard')->withErrors([
                'authorization' => 'You do not have permission to access the admin dashboard.',
            ]);
        }

        return $next($request);
    }
}
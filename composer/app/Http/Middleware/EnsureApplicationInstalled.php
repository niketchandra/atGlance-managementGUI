<?php

namespace App\Http\Middleware;

use App\Support\InstallationState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApplicationInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (InstallationState::isInstalled() || $request->routeIs('install.*')) {
            return $next($request);
        }

        return redirect()->route('install.show');
    }
}
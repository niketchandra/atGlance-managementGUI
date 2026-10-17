<?php

namespace App\Http\Middleware;

use App\Support\ApiIdentity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * sa.permission:<permission>, after atglance.system. A service account must hold the
 * permission on the calling system's workspace. Person keys pass through unchanged.
 */
class RequireServiceAccountPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $identity = ApiIdentity::current($request);
        $system = $request->attributes->get('system');

        if ($identity?->isServiceAccount() && !$identity->serviceAccount->can($permission, (int) ($system->workspace_id ?? 0))) {
            return response()->json(['message' => 'Service account lacks ' . $permission . ' in this workspace'], 403);
        }

        return $next($request);
    }
}

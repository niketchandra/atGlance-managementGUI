<?php

namespace App\Http\Middleware;

use App\Support\InstallationState;
use App\Support\License;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only mode without an active licence (none, expired or invalid): every write is
 * refused except signing in and out and uploading a licence file. Reads keep working.
 * Before installation the installer has its own licence step, so nothing is blocked.
 */
class EnforceLicense
{
    /** Route names that stay writable without a licence. */
    private const ALLOWED_ROUTES = [
        'login',
        'logout',
        'password.email',
        'admin.settings.licence',
        'install.run',
        'install.license.preview',
    ];

    /** Unnamed API paths that stay writable without a licence. */
    private const ALLOWED_PATHS = [
        'api/auth/login',
        'api/auth/logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()
            || !InstallationState::isInstalled()
            || $request->routeIs(...self::ALLOWED_ROUTES)
            || in_array(trim($request->path(), '/'), self::ALLOWED_PATHS, true)
            || License::isActive()) {
            return $next($request);
        }

        $status = License::status();
        $message = $status['state'] === License::EXPIRED
            ? 'The AtGlance licence has expired. The console is read-only until a new licence file is uploaded in Admin Settings > Licence.'
            : License::REQUIRED_MESSAGE;

        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => $message,
                'code' => $status['error'] ?? 'E_NO_LICENSE',
            ], 403);
        }

        return redirect()->back()->withErrors(['licence' => $message]);
    }
}

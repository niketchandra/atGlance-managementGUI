<?php

namespace App\Http\Middleware;

use App\Support\InstallationState;
use App\Support\License;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Without an active licence (none, expired or invalid):
 * - every write is refused except signing in and out and adding a licence;
 * - signed-in web pages are locked: the super admin is sent to Admin Settings > Licence, other
 *   users see a "licence required" page. API reads (agents, CLI) keep working. A user who has
 *   not completed the profile (date of birth and PIN) does that first: EnsureProfileSetupComplete
 *   sends them to the profile page, which stays open here.
 * Before installation nothing is blocked; the licence step of the installer is optional.
 */
class EnforceLicense
{
    /** Route names that stay writable without a licence. */
    private const ALLOWED_ROUTES = [
        'login',
        'logout',
        'password.email',
        'admin.settings.licence',
        // First sign-in: the profile (date of birth and PIN) is completed before the licence.
        'profile.update',
        'install.run',
        'install.license.preview',
        'install.license.verify',
    ];

    /** Unnamed API paths that stay writable without a licence. */
    private const ALLOWED_PATHS = [
        'api/auth/login',
        'api/auth/logout',
    ];

    /** Web pages a signed-in user can still open without a licence. */
    private const ALLOWED_PAGES = [
        'admin.settings',
        'login',
        'install.*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()
            && !$request->is('api/*')
            && !$request->expectsJson()
            && $request->user() !== null
            && InstallationState::isInstalled()
            && !$request->routeIs(...self::ALLOWED_PAGES)
            && self::profileComplete($request->user())
            && !License::isActive()) {
            if ((int) $request->user()->rbac_id === 100) {
                return redirect()->route('admin.settings', ['tab' => 'licence']);
            }

            return response()->view('errors.licence-required', [], 403);
        }

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

    /** Date of birth and PIN set (the same check as EnsureProfileSetupComplete). */
    private static function profileComplete($user): bool
    {
        return !empty($user->dob) && !empty($user->pin);
    }
}

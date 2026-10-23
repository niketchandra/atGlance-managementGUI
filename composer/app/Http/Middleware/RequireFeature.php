<?php

namespace App\Http\Middleware;

use App\Support\Licensing\Entitlements;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `feature:<key>`: the request is refused unless the active licence enables
 * the feature. APIs get 403 {code: FEATURE_NOT_LICENSED}; pages show the "not included" page.
 * The feature's data is never touched.
 */
class RequireFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (Entitlements::isFeatureEnabled($feature)) {
            return $next($request);
        }

        $message = Entitlements::notLicensedMessage($feature);

        if ($request->is('api/*') || $request->expectsJson() || !$request->isMethodSafe()) {
            return response()->json([
                'success' => false,
                'code' => Entitlements::ERROR_CODE,
                'feature' => $feature,
                'message' => $message,
            ], 403);
        }

        return response()->view('errors.feature-not-licensed', [
            'feature' => $feature,
            'featureName' => Entitlements::name($feature),
            'message' => $message,
        ], 403);
    }
}

<?php

namespace App\Http\Middleware;

use App\Support\DomainSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session cookie follows the request scheme, and only the configured domain
 * is redirected to HTTPS (302, GET/HEAD only, through a proxy). IPs,
 * localhost, atglance.internal and direct hits on :8000 are never
 * redirected, so they always work for recovery.
 */
class EnforceDomainHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (env('SESSION_SECURE_COOKIE') === null) {
            config(['session.secure' => $request->isSecure()]);
        }

        // Only requests that came through a proxy (built-in Caddy or a platform
        // load balancer) are redirected; a direct hit on the app port (:8000)
        // is the recovery path and is never redirected.
        // An untrusted proxy's X-Forwarded-Proto is ignored, so redirecting it
        // could loop; it is flagged on the Plugins card instead.
        $viaProxy = $request->isFromTrustedProxy()
            && ($request->headers->has('X-Forwarded-For') || $request->headers->has('X-Forwarded-Proto'));

        if ($viaProxy
            && !$request->isSecure()
            && in_array($request->method(), ['GET', 'HEAD'], true)
            && DomainSettings::pluginEnabled()
            && DomainSettings::httpsOn()) {
            $host = strtolower($request->getHost());
            $domain = DomainSettings::domain();

            if ($domain !== '' && $host === $domain && !DomainSettings::isFallbackHost($host)) {
                return redirect()->to('https://' . $domain . $request->getRequestUri(), 302);
            }
        }

        return $next($request);
    }
}

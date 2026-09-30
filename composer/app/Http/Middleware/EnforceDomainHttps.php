<?php

namespace App\Http\Middleware;

use App\Support\DomainSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session cookie follows the request scheme, and only the configured domain
 * is redirected to HTTPS (302, GET/HEAD only). IPs, localhost and
 * atglance.internal are never redirected, so they always work for recovery.
 */
class EnforceDomainHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (env('SESSION_SECURE_COOKIE') === null) {
            config(['session.secure' => $request->isSecure()]);
        }

        if (!$request->isSecure()
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

<?php

namespace App\Http\Middleware;

use App\Support\DomainSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Records whether requests reach the app through the built-in proxy or a platform proxy (Plugins card status). */
class DetectFrontProxy
{
    public function handle(Request $request, Closure $next): Response
    {
        if (DomainSettings::pluginEnabled()) {
            $type = null;
            $forwarded = $request->headers->has('X-Forwarded-Proto') || $request->headers->has('X-Forwarded-For');
            if ($request->headers->get('X-AtGlance-Proxy') === 'builtin'
                && in_array((string) $request->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true)) {
                $type = DomainSettings::PROXY_BUILTIN;
            } elseif ($forwarded && $request->isFromTrustedProxy()) {
                $type = DomainSettings::PROXY_PLATFORM;
            } elseif ($forwarded) {
                // A proxy we do not trust: its headers are ignored. The Plugins
                // card shows its address so it can go into ATGLANCE_TRUSTED_PROXIES.
                $type = DomainSettings::PROXY_UNTRUSTED;
            }

            if ($type !== null) {
                DomainSettings::recordProxy($type, $request->isSecure() ? 'https' : 'http', (string) $request->server('REMOTE_ADDR'));
            }
        }

        return $next($request);
    }
}

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
            if ($request->headers->get('X-AtGlance-Proxy') === 'builtin'
                && in_array((string) $request->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true)) {
                $type = DomainSettings::PROXY_BUILTIN;
            } elseif ($request->headers->has('X-Forwarded-Proto') || $request->headers->has('X-Forwarded-For')) {
                $type = DomainSettings::PROXY_PLATFORM;
            }

            if ($type !== null) {
                DomainSettings::recordProxy($type, $request->isSecure() ? 'https' : 'http');
            }
        }

        return $next($request);
    }
}

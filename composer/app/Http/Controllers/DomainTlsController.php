<?php

namespace App\Http\Controllers;

use App\Support\CustomCertificate;
use App\Support\DomainSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Endpoints for the built-in Caddy proxy (docker/Caddyfile). Only local
 * requests without proxy headers are answered: behind Caddy every proxied
 * request also comes from 127.0.0.1, so the headers are what tell Caddy's own
 * calls apart from a visitor's request.
 */
class DomainTlsController extends Controller
{
    /** on_demand_tls "ask": may Caddy get a certificate for this name? */
    public function allowed(Request $request): Response
    {
        if (!self::isLocal($request) || !DomainSettings::pluginEnabled()) {
            return response('', 404);
        }

        $name = DomainSettings::normalizeDomain((string) $request->query('domain', ''));
        if ($name === DomainSettings::FALLBACK_HOST) {
            return response('', 200);
        }

        $modeAllows = in_array(DomainSettings::httpsMode(), [DomainSettings::MODE_BUILTIN, DomainSettings::MODE_CUSTOM], true);

        return response('', $modeAllows && $name !== '' && $name === DomainSettings::domain() ? 200 : 404);
    }

    /** get_certificate http: the organisation's certificate in mode "custom", else 204. */
    public function certificate(Request $request): Response
    {
        $name = DomainSettings::normalizeDomain((string) $request->query('server_name', ''));

        if (!self::isLocal($request)
            || !DomainSettings::pluginEnabled()
            || DomainSettings::httpsMode() !== DomainSettings::MODE_CUSTOM
            || $name === ''
            || $name !== DomainSettings::domain()) {
            return response('', 204);
        }

        $bundle = CustomCertificate::current();
        if ($bundle === null || !CustomCertificate::covers($bundle, $name)) {
            return response('', 204);
        }

        return response(CustomCertificate::pemBundle($bundle), 200, [
            'Content-Type' => 'application/x-pem-file',
            'Cache-Control' => 'no-store',
        ]);
    }

    public static function isLocal(Request $request): bool
    {
        return in_array((string) $request->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true)
            && !$request->headers->has('X-Forwarded-For')
            && !$request->headers->has('X-AtGlance-Proxy');
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\SystemRegister;
use App\Support\SecurityEvents;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Adds deprecation headers to legacy CLI API routes.
 * The legacy routes will be removed on 2026-12-31.
 */
class LegacyApiSunset
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Deprecation', 'true');
        $response->headers->set('Sunset', 'Thu, 31 Dec 2026 23:59:59 GMT');
        $docsUrl = config('app.docs_url') ?: rtrim((string) config('app.url'), '/') . '/docs';
        $response->headers->set('Link', '<' . $docsUrl . '/api-reference>; rel="successor-version"');

        return $response;
    }

    /**
     * Runs after the response is sent. Recording never affects the response.
     */
    public function terminate(Request $request, Response $response): void
    {
        try {
            if (!$response->isSuccessful()) {
                return;
            }

            $user = $request->user();
            if ($user) {
                $type = 'user';
                $id = (string) $user->id;
            } elseif (trim((string) $request->header('X-Atglance-System-Id')) !== '') {
                $type = 'system';
                $id = trim((string) $request->header('X-Atglance-System-Id'));
            } else {
                return;
            }

            // Once per identifier per day.
            $now = now();
            $key = 'legacy_api_used:' . $type . ':' . $id . ':' . $now->format('Y-m-d');
            $ttl = max(1, $now->diffInSeconds($now->copy()->endOfDay()) + 1);
            if (!Cache::add($key, 1, (int) $ttl)) {
                return;
            }

            $orgId = $user?->org_id;
            if ($orgId === null && $type === 'system' && ctype_digit($id)) {
                $orgId = SystemRegister::query()->whereKey((int) $id)->value('org_id');
            }

            SecurityEvents::recordLegacyApiUsed(
                $orgId !== null ? (int) $orgId : null,
                $user ? (int) $user->id : null,
                $request->ip(),
                [
                    'route' => '/' . ltrim($request->path(), '/'),
                    'method' => $request->method(),
                    'user_agent' => $request->userAgent(),
                ]
            );
        } catch (Throwable $e) {
            Log::warning('Legacy API usage not recorded', ['error' => $e->getMessage()]);
        }
    }
}

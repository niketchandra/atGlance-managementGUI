<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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

        // Add deprecation headers to the response
        $response->header('Deprecation', 'true');
        $response->header('Sunset', 'Thu, 31 Dec 2026 23:59:59 GMT');
        $response->header('Link', '<https://docs.atglance.example.com/docs/api-reference#cli-api-v1-apiv1>; rel="successor-version"');

        // Record legacy API usage (one entry per system/token per day)
        // This is done asynchronously to avoid blocking the response
        if ($response->isSuccessful()) {
            \Illuminate\Support\Facades\Queue::push(function () use ($request) {
                $this->recordLegacyUsage($request);
            });
        }

        return $response;
    }

    /**
     * Record that a legacy API route was used.
     * One activity per (system_id or token_id, day) to avoid spam.
     */
    private function recordLegacyUsage(Request $request): void
    {
        try {
            $identifierType = null;
            $identifierId = null;

            // Identify by PAT token if available
            if ($request->user()) {
                $identifierType = 'user_id';
                $identifierId = $request->user()->id;
            } elseif ($request->header('X-Atglance-System-Id')) {
                $identifierType = 'system_id';
                $identifierId = $request->header('X-Atglance-System-Id');
            }

            if (!$identifierType || !$identifierId) {
                return;
            }

            // One entry per day per identifier
            $today = now()->startOfDay();
            \App\Models\ActivityLog::updateOrCreate(
                [
                    'event' => 'legacy_api.used',
                    $identifierType => $identifierId,
                    'created_date' => $today,
                ],
                [
                    'route' => $request->path(),
                    'method' => $request->method(),
                    'user_agent' => $request->userAgent(),
                    'ip' => $request->ip(),
                    'details' => [
                        'route' => $request->path(),
                        'method' => $request->method(),
                        'user_agent' => $request->userAgent(),
                    ],
                ]
            );
        } catch (\Throwable $e) {
            // Silently fail to not impact API responses
            \Illuminate\Support\Facades\Log::warning('Failed to record legacy API usage', ['error' => $e->getMessage()]);
        }
    }
}

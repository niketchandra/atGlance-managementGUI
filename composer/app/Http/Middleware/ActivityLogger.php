<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class ActivityLogger
{
    /**
     * Only log transactional operations.
     */
    private function shouldLog(Request $request): bool
    {
        $method = strtoupper($request->method());

        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }

        $path = '/' . ltrim($request->path(), '/');

        $transactionalPatterns = [
            '/api/auth/*',
            '/api/config-files/*',
            '/api/files/*',
            '/api/system-register*',
            '/api/system-deregister',
            '/api/users*',
            '/api/products*',
            '/settings/update',
            '/settings/api-keys*',
            '/password/update',
            '/login',
            '/logout',
            '/register',
        ];

        foreach ($transactionalPatterns as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            if (!Schema::hasTable('activity_logs')) {
                return $response;
            }

            if (!$this->shouldLog($request)) {
                return $response;
            }

            $user = $request->user();
            $payload = $request->except(['password', 'password_confirmation']);

            ActivityLog::create([
                'user_id' => $user?->id,
                'method' => $request->method(),
                'path' => '/' . ltrim($request->path(), '/'),
                'status_code' => $response->getStatusCode(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'request_payload' => empty($payload) ? null : json_encode($payload),
            ]);
        } catch (\Throwable $e) {
            // Do not block application flow if logging fails.
        }

        return $response;
    }
}

<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\ActivityLogger::class);

        // Only the built-in proxy (127.0.0.1) by default, so clients cannot forge
        // their IP with X-Forwarded-For. A platform load balancer is added with
        // ATGLANCE_TRUSTED_PROXIES (comma-separated IPs/CIDRs, replaces the default).
        $middleware->trustProxies(
            at: array_values(array_filter(array_map('trim', explode(',', (string) env(
                'ATGLANCE_TRUSTED_PROXIES',
                '127.0.0.1,::1'
            ))))),
            headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->web(append: [
            \App\Http\Middleware\DetectFrontProxy::class,
            \App\Http\Middleware\EnforceDomainHttps::class,
        ]);

        $middleware->alias([
            'auth.session' => \App\Http\Middleware\AuthenticateSession::class,
            'auth.pat' => \App\Http\Middleware\AuthenticatePatToken::class,
            'admin.role' => \App\Http\Middleware\AdminRoleMiddleware::class,
            'super.admin.role' => \App\Http\Middleware\SuperAdminRoleMiddleware::class,
            'active.user' => \App\Http\Middleware\EnsureUserIsActive::class,
            'profile.completed' => \App\Http\Middleware\EnsureProfileSetupComplete::class,
            'app.installed' => \App\Http\Middleware\EnsureApplicationInstalled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response) {
            if ($response->getStatusCode() === 419) {
                return redirect()->route('home');
            }

            return $response;
        });
    })->create();

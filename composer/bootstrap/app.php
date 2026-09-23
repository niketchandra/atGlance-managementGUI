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

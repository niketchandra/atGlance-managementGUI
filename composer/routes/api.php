<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\McpController;
use App\Http\Controllers\Api\PatTokenController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\SystemRegisterController;
use App\Http\Controllers\Api\TokenValidationController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\V1\ConfigFileController as V1ConfigFileController;
use App\Http\Controllers\Api\V1\MetaController as V1MetaController;
use App\Http\Controllers\Api\V1\SystemController as V1SystemController;
use App\Http\Controllers\DomainTlsController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth.session');

// Token validation - no authentication required
Route::post('/auth/validate-token', [TokenValidationController::class, 'validateToken']);
Route::get('/auth/validate-token', [TokenValidationController::class, 'validateFromHeader']);

// PAT token management - requires session token (temporary bearer token)
Route::post('/auth/pat-tokens', [PatTokenController::class, 'store'])->middleware('auth.session');
Route::get('/auth/pat-tokens', [PatTokenController::class, 'index'])->middleware('auth.session');

// User management: admin PAT only, scoped to the caller's org (authorization in
// Api\UserController). Removed from the Kong gateway (not a machine-client route).
Route::apiResource('users', UserController::class)->middleware(['auth.pat', 'active.user']);

// File operations - require PAT token only (permanent token with atgla- prefix)
Route::post('/files/upload', [FileController::class, 'upload'])->middleware('auth.pat');
Route::get('/files/{fileId}', [FileController::class, 'download'])->middleware('auth.pat');

// Configuration file operations - require PAT token only
Route::post('/config-files/upload', [FileController::class, 'uploadConfigFile'])->middleware('auth.pat');
Route::get('/config-files', [FileController::class, 'listConfigFiles'])->middleware('auth.pat');
Route::get('/config-files/filter', [FileController::class, 'listConfigFilesBySystemAndHash'])->middleware('auth.pat');
Route::get('/config-files/config-show', [FileController::class, 'listConfigVersionsBySystemValidationAndService'])->middleware('auth.pat');
Route::get('/config-files/{fileId}', [FileController::class, 'downloadConfigFile'])->middleware('auth.pat');
Route::get('/config-files/download/{id}', [FileController::class, 'downloadConfigFileById'])->middleware('auth.pat');
Route::get('/config-files/{fileId}/raw-data', [FileController::class, 'getRawData'])->middleware('auth.pat');
Route::delete('/config-files/{fileId}', [FileController::class, 'deleteConfigFile'])->middleware('auth.pat');

// Services operations - require PAT token only
Route::post('/services', [ServiceController::class, 'store'])->middleware('auth.pat');
Route::get('/services', [ServiceController::class, 'index'])->middleware('auth.pat');
Route::get('/services/{serviceId}', [ServiceController::class, 'show'])->middleware('auth.pat');

// System registration - requires PAT token only
Route::post('/system-register', [SystemRegisterController::class, 'store'])->middleware('auth.pat');
Route::get('/system-register', [SystemRegisterController::class, 'index'])->middleware('auth.pat');
Route::get('/system-register/pat/{patTokenId}', [SystemRegisterController::class, 'getByPatToken'])->middleware('auth.pat');
Route::get('/system-register/user/{userId}', [SystemRegisterController::class, 'getByUser'])->middleware('auth.pat');

// System deregistration - requires PAT token only
Route::post('/system-deregister', [SystemRegisterController::class, 'deregister'])->middleware('auth.pat');

// System reactivation - requires PAT token only
Route::post('/system-reactive', [SystemRegisterController::class, 'reactive'])->middleware('auth.pat');
Route::get('/system-reactive', [SystemRegisterController::class, 'reactive'])->middleware('auth.pat');

// Force system deregistration - no bearer token required; uses email + password/pin
// (rate limiter v1-force, shared with the /api/v1 force routes: AppServiceProvider)
Route::post('/system-deregister-force', [SystemRegisterController::class, 'deregisterForce'])->middleware('throttle:v1-force');
Route::get('/system-deregister-force', [SystemRegisterController::class, 'deregisterForce'])->middleware('throttle:v1-force');

// Force system reactivation - no bearer token required; uses email + password/pin
Route::post('/system-reactivate-force', [SystemRegisterController::class, 'reactiveForce'])->middleware('throttle:v1-force');
Route::get('/system-reactivate-force', [SystemRegisterController::class, 'reactiveForce'])->middleware('throttle:v1-force');

// Built-in proxy (Caddy) only; see DomainTlsController::isLocal().
Route::get('/internal/domain/tls-allowed', [DomainTlsController::class, 'allowed']);
Route::get('/internal/domain/certificate', [DomainTlsController::class, 'certificate']);

// Read-only API for the AtGlance MCP server (mcp/, docs/mcp.md). Answers as the PAT's user.
Route::prefix('mcp')->middleware(['auth.pat', 'throttle:120,1'])->group(function () {
    Route::get('/me', [McpController::class, 'me']);
    Route::get('/workspaces', [McpController::class, 'workspaces']);
    Route::get('/workspaces/{workspaceId}/ai-progress', [McpController::class, 'aiProgress'])->whereNumber('workspaceId');
    Route::get('/systems', [McpController::class, 'systems']);
    Route::get('/config-files', [McpController::class, 'configFiles']);
    Route::get('/config-files/{id}', [McpController::class, 'configFile'])->whereNumber('id');
    Route::get('/config-files/{id}/versions', [McpController::class, 'configVersions'])->whereNumber('id');
    Route::get('/vulnerabilities', [McpController::class, 'vulnerabilities']);
    Route::get('/dashboard', [McpController::class, 'dashboard']);
});

// API for the atglance CLI v2 (docs/api-reference.md, "CLI API v1"). Same /api/v1/* path
// on the gateway (:8002) and on the app (:8000). System-scoped routes identify the host
// with X-Atglance-System-Id + X-Atglance-System-Key (atglance.system, after auth.pat).
Route::prefix('v1')->name('v1.')->group(function () {
    Route::get('/meta', [V1MetaController::class, 'meta'])->middleware('throttle:v1-meta')->name('meta');
    Route::get('/whoami', [V1MetaController::class, 'whoami'])->middleware(['auth.pat:service', 'active.user'])->name('whoami');

    Route::post('/systems', [V1SystemController::class, 'store'])->middleware(['auth.pat', 'active.user'])->name('systems.store');

    // Ids are at most 18 digits (fits a signed 64-bit integer); longer ones are a 404.
    Route::middleware(['auth.pat:service', 'active.user', 'atglance.system'])->group(function () {
        Route::get('/systems/self', [V1SystemController::class, 'show'])->name('systems.self');
        Route::post('/systems/self/deregister', [V1SystemController::class, 'deregister'])->name('systems.deregister');
        Route::post('/systems/self/reactivate', [V1SystemController::class, 'reactivate'])->name('systems.reactivate');
        Route::post('/systems/self/rotate-key', [V1SystemController::class, 'rotateKey'])->middleware('sa.permission:systems.rotate_key')->name('systems.rotate-key');
        Route::post('/config-files', [V1ConfigFileController::class, 'store'])->middleware('sa.permission:configs.write')->name('config-files.store');
        Route::get('/config-files', [V1ConfigFileController::class, 'index'])->middleware('sa.permission:configs.read')->name('config-files.index');
        Route::get('/config-files/{id}', [V1ConfigFileController::class, 'show'])->middleware('sa.permission:configs.read')->where('id', '[0-9]{1,18}')->name('config-files.show');
    });

    // No bearer token: an admin of the system's organisation signs in with email + password/pin.
    Route::middleware('throttle:v1-force')->group(function () {
        Route::post('/systems/{id}/deregister-force', [V1SystemController::class, 'deregisterForce'])->where('id', '[0-9]{1,18}')->name('systems.deregister-force');
        Route::post('/systems/{id}/reactivate-force', [V1SystemController::class, 'reactivateForce'])->where('id', '[0-9]{1,18}')->name('systems.reactivate-force');
    });
});

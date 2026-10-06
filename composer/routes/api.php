<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\McpController;
use App\Http\Controllers\Api\PatTokenController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\SystemRegisterController;
use App\Http\Controllers\Api\TokenValidationController;
use App\Http\Controllers\Api\UserController;
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

Route::apiResource('users', UserController::class);

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
Route::post('/system-deregister-force', [SystemRegisterController::class, 'deregisterForce']);
Route::get('/system-deregister-force', [SystemRegisterController::class, 'deregisterForce']);

// Force system reactivation - no bearer token required; uses email + password/pin
Route::post('/system-reactivate-force', [SystemRegisterController::class, 'reactiveForce']);
Route::get('/system-reactivate-force', [SystemRegisterController::class, 'reactiveForce']);

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

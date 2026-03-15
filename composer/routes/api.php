<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\PatTokenController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\SystemRegisterController;
use App\Http\Controllers\Api\TokenValidationController;
use App\Http\Controllers\Api\UserController;
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
Route::apiResource('products', ProductController::class);

// File operations - require PAT token only (permanent token with atgla- prefix)
Route::post('/files/upload', [FileController::class, 'upload'])->middleware('auth.pat');
Route::get('/files/{fileId}', [FileController::class, 'download'])->middleware('auth.pat');

// Configuration file operations - require PAT token only
Route::post('/config-files/upload', [FileController::class, 'uploadConfigFile'])->middleware('auth.pat');
Route::get('/config-files', [FileController::class, 'listConfigFiles'])->middleware('auth.pat');
Route::get('/config-files/filter', [FileController::class, 'listConfigFilesBySystemAndHash'])->middleware('auth.pat');
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

// Force system deregistration - requires session token (bearer token) - ADMIN API
Route::post('/system-deregister-force', [SystemRegisterController::class, 'deregisterForce'])->middleware('auth.session');
Route::get('/system-deregister-force', [SystemRegisterController::class, 'deregisterForce'])->middleware('auth.session');

// Force system reactivation - requires session token (bearer token) - ADMIN API
Route::post('/system-reactivate-force', [SystemRegisterController::class, 'reactiveForce'])->middleware('auth.session');
Route::get('/system-reactivate-force', [SystemRegisterController::class, 'reactiveForce'])->middleware('auth.session');

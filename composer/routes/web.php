<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;

// Root route - show login/registration on left sidebar, content on right
Route::get('/', function () {
    return view('app');
})->name('home');

Route::get('/login', function () {
    return redirect()->route('home');
})->name('login.form');

// Authentication routes
Route::post('/login', [AuthController::class, 'login'])->name('login');
Route::post('/register', [AuthController::class, 'register'])->name('register');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
Route::post('/password/email', [AuthController::class, 'sendPasswordResetLink'])->name('password.email');
Route::post('/contact', [AuthController::class, 'storeContact'])->name('contact');

// Protected routes - requires web session authentication
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/configuration-backups', [DashboardController::class, 'configurationBackups'])->name('configuration-backups');
    Route::get('/configuration-backups/service-name/{serviceName}/versions', [DashboardController::class, 'viewServiceVersionsByName'])->name('configuration-backups.service-versions-by-name');
    Route::get('/configuration-backups/service/{serviceId}/versions', [DashboardController::class, 'viewServiceVersions'])->name('configuration-backups.service-versions');
    Route::get('/configuration-backups/{id}/view', [DashboardController::class, 'viewConfigurationFile'])->name('configuration-backups.view');
    Route::get('/configuration-backups/{id}/download', [DashboardController::class, 'downloadConfigurationFile'])->name('configuration-backups.download');
    Route::get('/systems-registered', [DashboardController::class, 'systemsRegistered'])->name('systems-registered');
    Route::get('/systems-registered/{systemId}/services', [DashboardController::class, 'systemServices'])->name('systems-registered.services');
    Route::get('/live-service-monitoring', [DashboardController::class, 'liveServiceMonitoring'])->name('live-service-monitoring');
    Route::get('/vulnerabilities-identified', [DashboardController::class, 'vulnerabilitiesIdentified'])->name('vulnerabilities-identified');
    Route::get('/settings', [DashboardController::class, 'settings'])->name('settings');
    Route::post('/settings/update', [DashboardController::class, 'updateSettings'])->name('settings.update');
    Route::post('/settings/api-keys', [DashboardController::class, 'createApiKey'])->name('settings.api-keys.create');
    Route::post('/settings/api-keys/view', [DashboardController::class, 'viewApiKey'])->name('settings.api-keys.view');
    Route::post('/settings/api-keys/revoke', [DashboardController::class, 'revokeApiKey'])->name('settings.api-keys.revoke');
    Route::post('/password/update', [DashboardController::class, 'updatePassword'])->name('password.update');
    Route::get('/profile', [DashboardController::class, 'profile'])->name('profile');
    Route::get('/products', [DashboardController::class, 'products'])->name('products');
});

<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\InstallerController;
use App\Support\InstallationState;

Route::get('/install', [InstallerController::class, 'show'])->name('install.show');
Route::post('/install', [InstallerController::class, 'install'])->name('install.run');
Route::get('/install/info', [InstallerController::class, 'info'])->name('install.info');

// Root route - installer first, then login/registration app page
Route::get('/', function () {
    if (!InstallationState::isInstalled()) {
        return redirect()->route('install.show');
    }

    return view('app');
})->name('home');

Route::get('/login', function () {
    return redirect()->route('home');
})->name('login.form');

Route::middleware('app.installed')->group(function () {
    // Authentication routes
    Route::post('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/auth/sso/{provider}', [AuthController::class, 'redirectToSso'])->name('auth.sso.redirect');
    Route::get('/auth/sso/{provider}/callback', [AuthController::class, 'handleSsoCallback'])->name('auth.sso.callback');
    Route::post('/password/email', [AuthController::class, 'sendPasswordResetLink'])->name('password.email');
    Route::post('/contact', [AuthController::class, 'storeContact'])->name('contact');
    Route::get('/site-logo/{path?}', [AdminDashboardController::class, 'serveSiteLogo'])
        ->where('path', '.*')
        ->name('site.logo');

    // Protected routes - requires web session authentication
    Route::middleware(['auth', 'active.user', 'profile.completed'])->group(function () {
        Route::post('/workspace/select', [DashboardController::class, 'selectWorkspace'])->name('workspace.select');

        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/configuration-backups', [DashboardController::class, 'configurationBackups'])->name('configuration-backups');
        Route::get('/configuration-backups/service-name/{serviceName}/versions', [DashboardController::class, 'viewServiceVersionsByName'])->name('configuration-backups.service-versions-by-name');
        Route::get('/configuration-backups/service/{serviceId}/versions', [DashboardController::class, 'viewServiceVersions'])->name('configuration-backups.service-versions');
        Route::get('/configuration-backups/{id}/view', [DashboardController::class, 'viewConfigurationFile'])->name('configuration-backups.view');
        Route::get('/configuration-backups/{id}/download', [DashboardController::class, 'downloadConfigurationFile'])->name('configuration-backups.download');
        Route::get('/systems-registered', [DashboardController::class, 'systemsRegistered'])->name('systems-registered');
        Route::get('/systems-registered/{systemId}/edit', [DashboardController::class, 'editRegisteredSystem'])->name('systems-registered.edit');
        Route::put('/systems-registered/{systemId}', [DashboardController::class, 'updateRegisteredSystem'])->name('systems-registered.update');
        Route::delete('/systems-registered/{systemId}', [DashboardController::class, 'deleteRegisteredSystem'])->name('systems-registered.delete');
        Route::get('/systems-registered/{systemId}/services', [DashboardController::class, 'systemServices'])->name('systems-registered.services');
        Route::get('/live-service-monitoring', [DashboardController::class, 'liveServiceMonitoring'])->name('live-service-monitoring');
        Route::get('/vulnerabilities-identified', [DashboardController::class, 'vulnerabilitiesIdentified'])->name('vulnerabilities-identified');
        Route::get('/settings', [DashboardController::class, 'settings'])->name('settings');
        Route::post('/settings/update', [DashboardController::class, 'updateSettings'])->name('settings.update');
        Route::post('/settings/pin/reset', [DashboardController::class, 'resetPin'])->name('settings.pin.reset');
        Route::post('/settings/pin/reset/sso', [DashboardController::class, 'beginSsoPinReset'])->name('settings.pin.reset.sso');
        Route::post('/settings/api-keys', [DashboardController::class, 'createApiKey'])->name('settings.api-keys.create');
        Route::post('/settings/api-keys/view', [DashboardController::class, 'viewApiKey'])->name('settings.api-keys.view');
        Route::post('/settings/api-keys/revoke', [DashboardController::class, 'revokeApiKey'])->name('settings.api-keys.revoke');
        Route::post('/password/update', [DashboardController::class, 'updatePassword'])->name('password.update');
        Route::get('/profile', [DashboardController::class, 'profile'])->name('profile');
        Route::post('/profile/setup', [DashboardController::class, 'updateProfileSetup'])->name('profile.update');
        Route::get('/products', [DashboardController::class, 'products'])->name('products');

        Route::middleware('admin.role')->prefix('admin')->group(function () {
            Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('admin.dashboard');
            Route::get('/users', [AdminDashboardController::class, 'usersIndex'])->name('admin.users');
            Route::post('/users', [AdminDashboardController::class, 'createUser'])->name('admin.users.store');
            Route::get('/users/{user}', [AdminDashboardController::class, 'userDashboard'])->name('admin.users.show');
            Route::get('/users/{user}/profile', [AdminDashboardController::class, 'userProfile'])->name('admin.users.profile');
            Route::put('/users/{user}', [AdminDashboardController::class, 'updateUser'])->name('admin.users.update');
            Route::get('/workspaces', [AdminDashboardController::class, 'adminWorkspaces'])->name('admin.workspaces');
            Route::get('/workspaces/{workspaceId}', [AdminDashboardController::class, 'viewWorkspace'])->name('admin.workspaces.show');
            Route::post('/workspaces/{workspaceId}/users', [AdminDashboardController::class, 'addUserToWorkspace'])->name('admin.workspaces.users.add');
            Route::delete('/workspaces/{workspaceId}/users/{userId}', [AdminDashboardController::class, 'removeUserFromWorkspace'])->name('admin.workspaces.users.remove');
            Route::get('/users/{user}/systems/{systemId}/services', [AdminDashboardController::class, 'userSystemServices'])->name('admin.users.systems.services');
            Route::get('/users/{user}/services/{serviceId}/versions', [AdminDashboardController::class, 'userServiceVersions'])->name('admin.users.services.versions');
            Route::get('/settings', [AdminDashboardController::class, 'settings'])->name('admin.settings');
            Route::post('/settings/site', [AdminDashboardController::class, 'updateSiteSettings'])->name('admin.settings.site');
            Route::post('/settings/s3', [AdminDashboardController::class, 'updateS3Settings'])->name('admin.settings.s3');
            Route::post('/settings/backup-restore', [AdminDashboardController::class, 'updateBackupRestoreSettings'])->name('admin.settings.backup-restore');
            Route::post('/settings/migration/config', [AdminDashboardController::class, 'updateMigrationSettings'])->name('admin.settings.migration.config');
            Route::post('/settings/migration/analyze', [AdminDashboardController::class, 'analyzeMigration'])->name('admin.settings.migration.analyze');
            Route::post('/settings/migration/start', [AdminDashboardController::class, 'startMigration'])->name('admin.settings.migration.start');
            Route::post('/settings/mail', [AdminDashboardController::class, 'updateMailSettings'])->name('admin.settings.mail');
            Route::post('/settings/sso', [AdminDashboardController::class, 'updateSsoSettings'])->name('admin.settings.sso');
        });

        Route::middleware('super.admin.role')->group(function () {
            Route::get('/entrpirse_console', [AdminDashboardController::class, 'enterpriseConsole'])->name('enterprise.console');
            Route::post('/entrpirse_console/workspaces', [AdminDashboardController::class, 'createEnterpriseWorkspace'])->name('enterprise.workspaces.store');
            Route::post('/entrpirse_console/organizations', [AdminDashboardController::class, 'createEnterpriseOrganization'])->name('enterprise.organizations.store');
            Route::get('/workspace/{workspaceId}', [AdminDashboardController::class, 'viewWorkspace'])->name('workspace.detail');
            Route::put('/workspace/{workspaceId}', [AdminDashboardController::class, 'updateWorkspace'])->name('workspace.update');
            Route::delete('/workspace/{workspaceId}', [AdminDashboardController::class, 'deleteWorkspace'])->name('workspace.destroy');
            Route::post('/workspace/{workspaceId}/admins', [AdminDashboardController::class, 'addAdminToWorkspace'])->name('workspace.admins.add');
            Route::post('/workspace/{workspaceId}/users', [AdminDashboardController::class, 'addUserToWorkspace'])->name('workspace.users.add');
            Route::delete('/workspace/{workspaceId}/users/{userId}', [AdminDashboardController::class, 'removeUserFromWorkspace'])->name('workspace.users.remove');
        });
    });
});

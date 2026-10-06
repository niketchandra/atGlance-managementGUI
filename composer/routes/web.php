<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\McpConnectController;
use App\Http\Controllers\WorkspaceSettingsController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AiConnectController;
use App\Http\Controllers\BackupRestoreController;
use App\Http\Controllers\DomainSettingsController;
use App\Http\Controllers\InstallerController;
use App\Http\Controllers\LicenseController;
use App\Http\Controllers\MailTestController;
use App\Http\Controllers\NotificationSettingsController;
use App\Http\Controllers\NotificationsController;
use App\Http\Controllers\PublicPageController;
use App\Support\InstallationState;
use App\Support\SiteProfile;

Route::get('/install', [InstallerController::class, 'show'])->name('install.show');
Route::post('/install', [InstallerController::class, 'install'])->name('install.run');
Route::post('/install/license/verify', [InstallerController::class, 'verifyLicense'])
    ->middleware('throttle:10,1')
    ->name('install.license.verify');
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
    Route::post('/contact', [PublicPageController::class, 'submitContact'])
        ->middleware('throttle:5,1')
        ->name('contact');
    Route::get('/{page}', [PublicPageController::class, 'show'])
        ->whereIn('page', SiteProfile::PAGES)
        ->name('public.page');
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
        Route::post('/configuration-backups/{id}/ai-validate', [DashboardController::class, 'validateConfigurationWithAi'])->whereNumber('id')->middleware('throttle:10,1')->name('configuration-backups.ai-validate');
        Route::get('/configuration-backups/{id}/ai-validations/{validationId}', [DashboardController::class, 'showConfigurationAiValidation'])->whereNumber(['id', 'validationId'])->name('configuration-backups.ai-validations.show');
        Route::delete('/configuration-backups/{id}/ai-validations/{validationId}', [DashboardController::class, 'deleteConfigurationAiValidation'])->whereNumber(['id', 'validationId'])->name('configuration-backups.ai-validations.delete');
        Route::get('/systems-registered', [DashboardController::class, 'systemsRegistered'])->name('systems-registered');
        Route::get('/systems-registered/{systemId}/edit', [DashboardController::class, 'editRegisteredSystem'])->name('systems-registered.edit');
        Route::put('/systems-registered/{systemId}', [DashboardController::class, 'updateRegisteredSystem'])->name('systems-registered.update');
        Route::delete('/systems-registered/{systemId}', [DashboardController::class, 'deleteRegisteredSystem'])->name('systems-registered.delete');
        Route::get('/systems-registered/{systemId}/services', [DashboardController::class, 'systemServices'])->name('systems-registered.services');
        Route::get('/live-service-monitoring', [DashboardController::class, 'liveServiceMonitoring'])->name('live-service-monitoring');
        Route::get('/vulnerabilities-identified', [DashboardController::class, 'vulnerabilitiesIdentified'])->name('vulnerabilities-identified');
        Route::get('/connect-ai', McpConnectController::class)->name('mcp.connect');
        Route::get('/settings', [DashboardController::class, 'settings'])->name('settings');
        Route::post('/settings/update', [DashboardController::class, 'updateSettings'])->name('settings.update');
        Route::post('/settings/pin/reset', [DashboardController::class, 'resetPin'])->name('settings.pin.reset');
        Route::post('/settings/pin/reset/sso', [DashboardController::class, 'beginSsoPinReset'])->name('settings.pin.reset.sso');
        Route::post('/settings/api-keys', [DashboardController::class, 'createApiKey'])->name('settings.api-keys.create');
        Route::post('/settings/api-keys/view', [DashboardController::class, 'viewApiKey'])->name('settings.api-keys.view');
        Route::post('/settings/api-keys/revoke', [DashboardController::class, 'revokeApiKey'])->name('settings.api-keys.revoke');
        Route::post('/password/update', [DashboardController::class, 'updatePassword'])->name('password.update');
        Route::post('/settings/preferences', [DashboardController::class, 'updatePreferences'])->name('settings.preferences');
        Route::post('/settings/notifications', [DashboardController::class, 'updateNotificationPreferences'])->name('settings.notifications');
        Route::post('/settings/preferences/timezone', [DashboardController::class, 'detectTimezone'])->name('settings.preferences.timezone');
        Route::delete('/settings/sessions/{session}', [DashboardController::class, 'endSession'])->name('settings.sessions.end');
        Route::post('/settings/sessions/others', [DashboardController::class, 'endOtherSessions'])->name('settings.sessions.others');
        Route::get('/profile', [DashboardController::class, 'profile'])->name('profile');
        Route::get('/profile/activity', [DashboardController::class, 'profileActivity'])->name('profile.activity');
        Route::post('/profile/setup', [DashboardController::class, 'updateProfileSetup'])->name('profile.update');
        Route::get('/products', [DashboardController::class, 'products'])->name('products');

        Route::middleware('admin.role')->prefix('admin')->group(function () {
            Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('admin.dashboard');
            Route::get('/users', [AdminDashboardController::class, 'usersIndex'])->name('admin.users');
            Route::post('/users', [AdminDashboardController::class, 'createUser'])->name('admin.users.store');
            Route::get('/users/{user}', [AdminDashboardController::class, 'userDashboard'])->name('admin.users.show');
            Route::get('/users/{user}/profile', [AdminDashboardController::class, 'userProfile'])->name('admin.users.profile');
            Route::put('/users/{user}', [AdminDashboardController::class, 'updateUser'])->name('admin.users.update');
            Route::post('/workspaces', [AdminDashboardController::class, 'createAdminWorkspace'])->name('admin.workspaces.store');
            Route::get('/users/{user}/systems/{systemId}/services', [AdminDashboardController::class, 'userSystemServices'])->name('admin.users.systems.services');
            Route::get('/users/{user}/services/{serviceId}/versions', [AdminDashboardController::class, 'userServiceVersions'])->name('admin.users.services.versions');
            Route::get('/settings', [AdminDashboardController::class, 'settings'])->name('admin.settings');
            Route::post('/settings/site', [AdminDashboardController::class, 'updateSiteSettings'])->name('admin.settings.site');
            Route::delete('/settings/contact-submissions/{submissionId}', [AdminDashboardController::class, 'deleteContactSubmission'])->whereNumber('submissionId')->name('admin.settings.contact-submissions.delete');
            Route::post('/settings/s3', [AdminDashboardController::class, 'updateS3Settings'])->name('admin.settings.s3');
            Route::post('/settings/s3/plugin', [AdminDashboardController::class, 'toggleS3Plugin'])->name('admin.settings.s3.plugin');
            Route::post('/settings/backup-restore', [AdminDashboardController::class, 'updateBackupRestoreSettings'])->name('admin.settings.backup-restore');
            Route::get('/settings/backups', [BackupRestoreController::class, 'index'])->name('admin.settings.backups');
            Route::post('/settings/restore', [BackupRestoreController::class, 'restore'])->name('admin.settings.restore');
            Route::post('/settings/backups/run', [BackupRestoreController::class, 'run'])->middleware('throttle:6,1')->name('admin.settings.backups.run');
            Route::post('/settings/notifications', [NotificationSettingsController::class, 'update'])->name('admin.settings.notifications');
            Route::post('/settings/ai', [AiConnectController::class, 'update'])->name('admin.settings.ai');
            Route::post('/settings/ai/plugin', [AiConnectController::class, 'togglePlugin'])->name('admin.settings.ai.plugin');
            Route::post('/settings/licence', [LicenseController::class, 'update'])->name('admin.settings.licence');
            Route::post('/settings/domain/plugin', [DomainSettingsController::class, 'togglePlugin'])->name('admin.settings.domain.plugin');
            Route::post('/settings/domain', [DomainSettingsController::class, 'save'])->name('admin.settings.domain');
            Route::post('/settings/domain/check', [DomainSettingsController::class, 'check'])->name('admin.settings.domain.check');
            Route::get('/settings/mcp', fn () => redirect()->route('admin.settings', ['tab' => 'plugins', 'plugin' => 'mcp']));
            Route::post('/settings/mcp', [AdminDashboardController::class, 'updateMcpSettings'])->name('admin.settings.mcp');
            Route::delete('/settings/domain/certificate', [DomainSettingsController::class, 'removeCertificate'])->name('admin.settings.domain.certificate.remove');
            Route::get('/settings/domain/ca.crt', [DomainSettingsController::class, 'downloadCa'])->name('admin.settings.domain.ca');
            Route::post('/settings/ai/test', [AiConnectController::class, 'test'])->name('admin.settings.ai.test');
            Route::post('/settings/ai/models', [AiConnectController::class, 'models'])->name('admin.settings.ai.models');
            Route::post('/settings/migration/config', [AdminDashboardController::class, 'updateMigrationSettings'])->name('admin.settings.migration.config');
            Route::post('/settings/migration/analyze', [AdminDashboardController::class, 'analyzeMigration'])->name('admin.settings.migration.analyze');
            Route::post('/settings/migration/start', [AdminDashboardController::class, 'startMigration'])->name('admin.settings.migration.start');
            Route::post('/settings/mail', [AdminDashboardController::class, 'updateMailSettings'])->name('admin.settings.mail');
            Route::post('/settings/mail/test', [MailTestController::class, 'send'])->middleware('throttle:5,1')->name('admin.settings.mail.test');
            Route::post('/settings/sso', [AdminDashboardController::class, 'updateSsoSettings'])->name('admin.settings.sso');
            Route::post('/settings/sso/plugin', [AdminDashboardController::class, 'toggleSsoPlugin'])->name('admin.settings.sso.plugin');
        });

        // Workspace admin is a per-workspace role, so any signed-in user may reach these;
        // the controller checks workspace_user.is_admin for the workspace.
        Route::prefix('admin')->group(function () {
            Route::get('/workspaces', [AdminDashboardController::class, 'adminWorkspaces'])->name('admin.workspaces');
            Route::get('/workspaces/{workspaceId}', [AdminDashboardController::class, 'viewWorkspace'])->name('admin.workspaces.show');
            Route::post('/workspaces/{workspaceId}/admins', [AdminDashboardController::class, 'addAdminToWorkspace'])->name('admin.workspaces.admins.add');
            Route::post('/workspaces/{workspaceId}/users', [AdminDashboardController::class, 'addUserToWorkspace'])->name('admin.workspaces.users.add');
            Route::delete('/workspaces/{workspaceId}/users/{userId}', [AdminDashboardController::class, 'removeUserFromWorkspace'])->name('admin.workspaces.users.remove');
            Route::put('/workspaces/{workspaceId}/users/{userId}/permissions', [AdminDashboardController::class, 'updateMemberPermissions'])->whereNumber(['workspaceId', 'userId'])->name('admin.workspaces.users.permissions');
            Route::get('/workspaces/{workspaceId}/ai/progress', [WorkspaceSettingsController::class, 'aiProgress'])->whereNumber('workspaceId')->name('admin.workspaces.ai.progress');
            Route::post('/workspaces/{workspaceId}/ai/reset', [WorkspaceSettingsController::class, 'resetAiQueue'])->whereNumber('workspaceId')->name('admin.workspaces.ai.reset');
            Route::put('/workspaces/{workspaceId}/settings/general', [WorkspaceSettingsController::class, 'updateGeneral'])->whereNumber('workspaceId')->name('admin.workspaces.settings.general');
            Route::put('/workspaces/{workspaceId}/settings/tags', [WorkspaceSettingsController::class, 'updateTags'])->whereNumber('workspaceId')->name('admin.workspaces.settings.tags');
            Route::put('/workspaces/{workspaceId}/settings/ai', [WorkspaceSettingsController::class, 'updateAi'])->whereNumber('workspaceId')->name('admin.workspaces.settings.ai');
            Route::post('/workspaces/{workspaceId}/ai/run', [WorkspaceSettingsController::class, 'runAiSweep'])->whereNumber('workspaceId')->middleware('throttle:6,1')->name('admin.workspaces.ai.run');
            Route::put('/workspaces/{workspaceId}/settings/backup', [WorkspaceSettingsController::class, 'updateBackup'])->whereNumber('workspaceId')->name('admin.workspaces.settings.backup');
            Route::post('/workspaces/{workspaceId}/backups/run', [WorkspaceSettingsController::class, 'runBackup'])->whereNumber('workspaceId')->middleware('throttle:6,1')->name('admin.workspaces.backups.run');
            Route::get('/workspaces/{workspaceId}/backups/{runId}/download', [WorkspaceSettingsController::class, 'downloadBackup'])->whereNumber(['workspaceId', 'runId'])->name('admin.workspaces.backups.download');
            Route::put('/workspaces/{workspaceId}/settings/notifications', [WorkspaceSettingsController::class, 'updateNotifications'])->whereNumber('workspaceId')->name('admin.workspaces.settings.notifications');
            // Notification groups: NotificationsController only offers the super admin's scopes or
            // the workspaces where the user is workspace admin (authorizeScope).
            Route::get('/notifications', [NotificationsController::class, 'index'])->name('admin.notifications');
            Route::post('/notifications/groups', [NotificationsController::class, 'store'])->name('admin.notifications.store');
            Route::put('/notifications/groups/{group}', [NotificationsController::class, 'update'])->name('admin.notifications.update');
            Route::delete('/notifications/groups/{group}', [NotificationsController::class, 'destroy'])->name('admin.notifications.destroy');
            Route::post('/notifications/groups/{group}/test', [NotificationsController::class, 'test'])->name('admin.notifications.test');
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

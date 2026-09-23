<?php

namespace App\Http\Controllers;

use App\Models\AdminSetting;
use App\Models\ConfigurationFile;
use App\Models\Organization;
use App\Models\Service;
use App\Models\SystemRegister;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function index(): View
    {
        return view('admin.dashboard', [
            'totalUsers' => User::count(),
            'totalSystems' => SystemRegister::count(),
            'totalServices' => Service::count(),
            'totalConfigFiles' => ConfigurationFile::count(),
        ]);
    }

    public function usersIndex(): View
    {
        return view('admin.users', [
            'users' => User::whereNotIn('rbac_id', [100, 101])->latest()->get(),
            'adminUsers' => User::where('rbac_id', 101)->latest()->get(),
        ]);
    }

    public function createUser(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in(['user', 'admin'])],
        ]);

        User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'rbac_id' => $validated['role'] === 'admin' ? 101 : 102,
            'org_id' => Organization::query()->min('id') ?? 200,
        ]);

        return redirect()->route('admin.users')->with('success', 'User registered successfully.');
    }

    public function userProfile(User $user): View
    {
        return view('admin.user-profile', compact('user'));
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::in(['user', 'admin'])],
        ]);

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'rbac_id' => $validated['role'] === 'admin' ? 101 : 102,
        ]);

        return redirect()->route('admin.users.profile', $user)->with('success', 'User profile updated successfully.');
    }

    public function settings(Request $request): View
    {
        $tab = $request->get('tab', 'info');

        $siteDomain = AdminSetting::getValue('site_domain', request()->getHttpHost());
        $organizationName = AdminSetting::getValue('site_organization_name', 'Default Organization');
        $localStorageBaseUrl = AdminSetting::getValue('site_local_storage_base_url', '/storage/app/public');
        $siteDomainAlias = AdminSetting::getValue('site_domain_alias', '');
        $siteDomainAliasIp = AdminSetting::getValue('site_domain_alias_ip', '');
        $siteHttpsEnabled = AdminSetting::getValue('site_https_enabled', false);
        $siteDescription = AdminSetting::getValue('site_description', '');
        $siteTagsText = AdminSetting::getValue('site_tags', '');
        $siteFeaturesText = AdminSetting::getValue('site_features', '');
        $siteMetadataText = AdminSetting::getValue('site_metadata', '');

        $useS3Storage = AdminSetting::getValue('s3_enabled', false);
        $s3AccessKey = AdminSetting::getValue('s3_access_key', '');
        $s3Region = AdminSetting::getValue('s3_region', '');
        $hasS3Secret = AdminSetting::where('setting_key', 's3_secret_key')->exists();

        $mailDriver = AdminSetting::getValue('mail_driver', 'log');
        $mailHost = AdminSetting::getValue('mail_host', '');
        $mailPort = AdminSetting::getValue('mail_port', '587');
        $mailUsername = AdminSetting::getValue('mail_username', '');
        $mailFromAddress = AdminSetting::getValue('mail_from_address', '');

        $ssoEnabled = AdminSetting::getValue('sso_enabled', false);
        $disableEmailRegistration = AdminSetting::getValue('disable_email_registration', false);
        $ssoEnabledProviders = json_decode(AdminSetting::getValue('sso_enabled_providers', '[]'), true) ?? [];
        $ssoRedirectUrl = AdminSetting::getValue('sso_redirect_url', '');
        $providerOptions = config('sso.providers', []);

        return view('admin.settings', compact(
            'tab',
            'siteDomain',
            'organizationName',
            'localStorageBaseUrl',
            'siteDomainAlias',
            'siteDomainAliasIp',
            'siteHttpsEnabled',
            'siteDescription',
            'siteTagsText',
            'siteFeaturesText',
            'siteMetadataText',
            'useS3Storage',
            's3AccessKey',
            's3Region',
            'hasS3Secret',
            'mailDriver',
            'mailHost',
            'mailPort',
            'mailUsername',
            'mailFromAddress',
            'ssoEnabled',
            'disableEmailRegistration',
            'ssoEnabledProviders',
            'ssoRedirectUrl',
            'providerOptions'
        ));
    }

    public function updateSiteSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'site_description' => ['nullable', 'string'],
            'site_domain_alias' => ['nullable', 'string', 'max:255'],
            'site_https_enabled' => ['nullable', 'boolean'],
            'site_tags' => ['nullable', 'string'],
            'site_features' => ['nullable', 'string'],
            'site_metadata' => ['nullable', 'string'],
        ]);

        AdminSetting::putValue('general', 'site_description', $validated['site_description'] ?? '');
        AdminSetting::putValue('general', 'site_domain_alias', $validated['site_domain_alias'] ?? '');
        AdminSetting::putValue('general', 'site_https_enabled', $request->boolean('site_https_enabled'));
        AdminSetting::putValue('general', 'site_tags', $validated['site_tags'] ?? '');
        AdminSetting::putValue('general', 'site_features', $validated['site_features'] ?? '');
        AdminSetting::putValue('general', 'site_metadata', $validated['site_metadata'] ?? '');

        return back()->with('success', 'Site settings updated successfully.');
    }

    public function updateMailSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'mail_driver' => ['required', Rule::in(['smtp', 'log', 'sendmail'])],
            'mail_host' => ['nullable', 'string'],
            'mail_port' => ['nullable', 'integer'],
            'mail_username' => ['nullable', 'string'],
            'mail_password' => ['nullable', 'string'],
            'mail_from_address' => ['nullable', 'email'],
        ]);

        AdminSetting::putValue('mail', 'mail_driver', $validated['mail_driver']);
        AdminSetting::putValue('mail', 'mail_host', $validated['mail_host'] ?? '');
        AdminSetting::putValue('mail', 'mail_port', $validated['mail_port'] ?? '587');
        AdminSetting::putValue('mail', 'mail_username', $validated['mail_username'] ?? '');
        if ($validated['mail_password'] ?? false) {
            AdminSetting::putValue('mail', 'mail_password', $validated['mail_password'], true);
        }
        AdminSetting::putValue('mail', 'mail_from_address', $validated['mail_from_address'] ?? '');

        return back()->with('success', 'Mail settings updated successfully.');
    }

    public function updateSsoSettings(Request $request): RedirectResponse
    {
        $providerOptions = config('sso.providers', []);
        $providerKeys = array_keys($providerOptions);

        $validated = $request->validate([
            'sso_enabled' => ['nullable', 'boolean'],
            'disable_email_registration' => ['nullable', 'boolean'],
            'sso_enabled_providers' => ['nullable', 'array'],
            'sso_enabled_providers.*' => [Rule::in($providerKeys)],
        ]);

        $enabledProviders = collect($request->input('sso_enabled_providers', []))
            ->map(fn ($provider) => strtolower(trim((string) $provider)))
            ->filter(fn ($provider) => in_array($provider, $providerKeys, true))
            ->unique()
            ->values()
            ->all();

        $effectiveSsoEnabled = $request->boolean('sso_enabled');

        if ($effectiveSsoEnabled && empty($enabledProviders)) {
            return back()->withErrors([
                'sso_enabled_providers' => 'Select at least one SSO provider when SSO is enabled.',
            ])->withInput();
        }

        AdminSetting::putValue('sso', 'sso_enabled', $effectiveSsoEnabled);
        AdminSetting::putValue('sso', 'disable_email_registration', $request->boolean('disable_email_registration'));
        AdminSetting::putValue('sso', 'sso_enabled_providers', json_encode($enabledProviders));

        return back()->with('success', 'SSO settings updated successfully.');
    }

    public function updateS3Settings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            's3_enabled' => ['nullable', 'boolean'],
            's3_access_key' => ['nullable', 'string', 'max:255'],
            's3_secret_key' => ['nullable', 'string'],
            's3_region' => ['nullable', 'string', 'max:100'],
            's3_bucket' => ['nullable', 'string', 'max:255'],
        ]);

        AdminSetting::putValue('s3', 's3_enabled', $request->boolean('s3_enabled'));
        AdminSetting::putValue('s3', 's3_access_key', $validated['s3_access_key'] ?? '');
        if ($validated['s3_secret_key'] ?? false) {
            AdminSetting::putValue('s3', 's3_secret_key', $validated['s3_secret_key'], true);
        }
        AdminSetting::putValue('s3', 's3_region', $validated['s3_region'] ?? '');
        AdminSetting::putValue('s3', 's3_bucket', $validated['s3_bucket'] ?? '');

        return back()->with('success', 'S3 settings updated successfully.');
    }

    public function updateBackupRestoreSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'backup_enabled' => ['nullable', 'boolean'],
            'backup_schedule' => ['nullable', 'string'],
            'backup_retention_days' => ['nullable', 'integer', 'min:1'],
        ]);

        AdminSetting::putValue('backup', 'backup_enabled', $request->boolean('backup_enabled'));
        AdminSetting::putValue('backup', 'backup_schedule', $validated['backup_schedule'] ?? 'daily');
        AdminSetting::putValue('backup', 'backup_retention_days', $validated['backup_retention_days'] ?? 30);

        return back()->with('success', 'Backup settings updated successfully.');
    }

    public function updateMigrationSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'migration_source' => ['nullable', 'string'],
            'migration_target' => ['nullable', 'string'],
            'migration_batch_size' => ['nullable', 'integer', 'min:100'],
        ]);

        AdminSetting::putValue('migration', 'migration_source', $validated['migration_source'] ?? '');
        AdminSetting::putValue('migration', 'migration_target', $validated['migration_target'] ?? '');
        AdminSetting::putValue('migration', 'migration_batch_size', $validated['migration_batch_size'] ?? 1000);

        return back()->with('success', 'Migration settings updated successfully.');
    }

    public function updateAiSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ai_enabled' => ['nullable', 'boolean'],
            'ai_provider' => ['nullable', 'string'],
            'ai_model' => ['nullable', 'string'],
            'ai_api_key' => ['nullable', 'string'],
        ]);

        AdminSetting::putValue('ai', 'ai_enabled', $request->boolean('ai_enabled'));
        AdminSetting::putValue('ai', 'ai_provider', $validated['ai_provider'] ?? '');
        AdminSetting::putValue('ai', 'ai_model', $validated['ai_model'] ?? '');
        if ($validated['ai_api_key'] ?? false) {
            AdminSetting::putValue('ai', 'ai_api_key', $validated['ai_api_key'], true);
        }

        return back()->with('success', 'AI settings updated successfully.');
    }
}
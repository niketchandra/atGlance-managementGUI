<?php

namespace App\Http\Controllers;

use App\Models\AdminSetting;
use App\Models\Organization;
use App\Models\User;
use App\Rules\IpAddressWithOptionalPort;
use App\Services\LicenseClient;
use App\Support\InstallationState;
use App\Support\License;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class InstallerController extends Controller
{
    public function show(): View|RedirectResponse
    {
        if (InstallationState::isInstalled()) {
            return redirect()->route('home');
        }

        $requestHost = (string) request()->getHttpHost();
        $defaultIp = $requestHost;
        if (str_contains($defaultIp, ':')) {
            $defaultIp = (string) strstr($defaultIp, ':', true);
        }

        if (!filter_var($defaultIp, FILTER_VALIDATE_IP)) {
            $defaultIp = (string) request()->server('SERVER_ADDR', '127.0.0.1');
        }

        return view('install.index', [
            'defaultIpAddress' => $defaultIp,
            'defaultDomain' => $requestHost,
            'licensePortalUrl' => License::portalUrl(),
        ]);
    }

    /**
     * "Verify" button on the installer: checks the key only. The licence is
     * activated for this console and organisation when the form is submitted.
     */
    public function verifyLicense(Request $request, LicenseClient $client): JsonResponse
    {
        if (InstallationState::isInstalled()) {
            return response()->json(['ok' => false, 'message' => 'Application is already installed.'], 403);
        }

        $result = $client->verify((string) $request->input('license_key', ''));

        return response()->json([
            'ok' => $result['ok'],
            'message' => $result['message'],
            'name' => $result['details']['name'] ?? null,
            'plan' => $result['details']['plan'] ?? null,
            'expires_at' => $result['details']['expires_at'] ?? null,
        ]);
    }

    public function install(Request $request, LicenseClient $licenseClient): RedirectResponse
    {
        if (InstallationState::isInstalled()) {
            return redirect()->route('home');
        }

        $validated = $request->validate([
            'organization_name' => ['required', 'string', 'max:255'],
            'app_ip' => ['required', 'string', 'max:255', new IpAddressWithOptionalPort()],
            'app_domain' => [
                'nullable',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $input = trim((string) $value);
                    if ($input === '') {
                        return;
                    }

                    if (preg_match('/^https?:\/\//i', $input)) {
                        $fail('Enter domain only, without http:// or https://.');
                        return;
                    }

                    if (str_contains($input, '/')) {
                        $fail('Domain cannot include path segments.');
                        return;
                    }

                    if (!preg_match('/^[A-Za-z0-9.-]+(?::\d{1,5})?$/', $input)) {
                        $fail('Enter a valid domain.');
                    }
                },
            ],
            'use_https' => ['required', 'in:0,1'],
            'superadmin_email' => ['required', 'email', 'max:255'],
            'superadmin_password' => ['required', 'string', 'min:8', 'confirmed'],
            'license_later' => ['nullable', 'in:0,1'],
            'license_key' => ['required_unless:license_later,1', 'nullable', 'string', 'max:512'],
        ], [
            'license_key.required_unless' => 'Enter your licence key, or tick "I\'ll add later".',
        ]);

        $organizationName = trim((string) $validated['organization_name']);

        // Links the licence to this console and organisation (marks it In Use on atglance.live).
        $licenseKey = $request->input('license_later') === '1' ? '' : trim((string) ($validated['license_key'] ?? ''));
        $licenseResult = null;
        if ($licenseKey !== '') {
            $licenseResult = $licenseClient->activate($licenseKey, $organizationName);
            if (!$licenseResult['ok']) {
                return back()
                    ->withInput($request->except(['superadmin_password', 'superadmin_password_confirmation']))
                    ->withErrors(['license_key' => 'Licence activation failed: ' . $licenseResult['message']]);
            }
        }

        $appIpAddress = strtolower(trim((string) $validated['app_ip']));
        $appDomainAlias = strtolower(trim((string) ($validated['app_domain'] ?? '')));
        $httpsEnabled = $validated['use_https'] === '1';
        $superAdminEmail = strtolower(trim((string) $validated['superadmin_email']));
        $superAdminPassword = (string) $validated['superadmin_password'];
        $normalizedUrl = ($httpsEnabled ? 'https://' : 'http://') . $appIpAddress;

        $this->updateEnv([
            'APP_URL' => $normalizedUrl,
            'APP_FORCE_HTTPS' => $httpsEnabled ? 'true' : 'false',
        ]);

        try {
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('db:seed', ['--force' => true]);

            if (Schema::hasTable('organizations')) {
                Organization::query()
                    ->where('id', 200)
                    ->update([
                        'name' => $organizationName,
                        'updated_at' => now(),
                    ]);
            }

            if (Schema::hasTable('users')) {
                // Always keep the default super admin account present without overriding existing values.
                User::query()->firstOrCreate(
                    ['email' => 'superadmin@admin.com'],
                    [
                        'rbac_id' => 100,
                        'org_id' => 200,
                        'name' => 'admin',
                        'password' => 'Atglance@123',
                        'status' => 'active',
                    ]
                );

                User::query()->updateOrCreate(
                    ['email' => $superAdminEmail],
                    [
                        'rbac_id' => 100,
                        'org_id' => 200,
                        'name' => strstr($superAdminEmail, '@', true) ?: $superAdminEmail,
                        'password' => $superAdminPassword,
                        'status' => 'active',
                    ]
                );
            }

            if (Schema::hasTable('admin_settings')) {
                AdminSetting::putValue('site', 'site_domain_alias', $appDomainAlias);
                AdminSetting::putValue('site', 'site_domain_alias_ip', $appIpAddress);
                AdminSetting::putValue('site', 'site_https_enabled', $httpsEnabled);

                if ($licenseResult !== null) {
                    License::store($licenseKey, $licenseResult);
                }
            }

            Artisan::call('optimize:clear');
        } catch (\Throwable $exception) {
            return back()
                ->withInput()
                ->withErrors(['install' => 'Installation failed: ' . $exception->getMessage()]);
        }

        InstallationState::markInstalled([
            'installed_at' => now()->toDateTimeString(),
            'organization_name' => $organizationName,
            'app_domain' => $appDomainAlias !== '' ? $appDomainAlias : $appIpAddress,
            'app_ip' => $appIpAddress,
            'app_alias_domain' => $appDomainAlias,
            'app_url' => $normalizedUrl,
            'https_enabled' => $httpsEnabled,
            'superadmin_email' => $superAdminEmail,
            'default_superadmin_email' => 'superadmin@admin.com',
            'superadmin_password' => $superAdminPassword,
            'license_active' => $licenseResult !== null,
            'license_name' => $licenseResult['details']['name'] ?? null,
            'license_plan' => $licenseResult['details']['plan'] ?? null,
        ]);

        return redirect()->route('install.info');
    }

    public function info(): View|RedirectResponse
    {
        if (!InstallationState::isInstalled()) {
            return redirect()->route('install.show');
        }

        return view('install.info', [
            'installation' => InstallationState::getData(),
        ]);
    }

    private function updateEnv(array $pairs): void
    {
        $envPath = base_path('.env');
        $envContent = is_file($envPath) ? (string) file_get_contents($envPath) : '';

        foreach ($pairs as $key => $value) {
            $pattern = "/^{$key}=.*/m";
            $line = $key . '=' . $value;

            if (preg_match($pattern, $envContent)) {
                $envContent = (string) preg_replace($pattern, $line, $envContent);
            } else {
                $envContent .= (str_ends_with($envContent, PHP_EOL) ? '' : PHP_EOL) . $line . PHP_EOL;
            }
        }

        file_put_contents($envPath, $envContent);
    }
}
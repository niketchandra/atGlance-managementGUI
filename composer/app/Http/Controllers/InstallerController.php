<?php

namespace App\Http\Controllers;

use App\Models\AdminSetting;
use App\Models\Organization;
use App\Models\User;
use App\Support\InstallationState;
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
        ]);
    }

    public function install(Request $request): RedirectResponse
    {
        if (InstallationState::isInstalled()) {
            return redirect()->route('home');
        }

        $validated = $request->validate([
            'organization_name' => ['required', 'string', 'max:255'],
            'app_ip' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $input = trim((string) $value);
                    if (preg_match('/^https?:\/\//i', $input)) {
                        $fail('Enter domain or IP only, without http:// or https://.');
                        return;
                    }

                    if (str_contains($input, '/')) {
                        $fail('IP address cannot include path segments.');
                        return;
                    }

                    if (!preg_match('/^([A-Fa-f0-9:.]+)(?::(\d{1,5}))?$/', $input, $matches)) {
                        $fail('Enter a valid IP address (optional port allowed).');
                        return;
                    }

                    $ipPart = $matches[1] ?? '';
                    $portPart = $matches[2] ?? null;

                    if (!filter_var($ipPart, FILTER_VALIDATE_IP)) {
                        $fail('Enter a valid IP address.');
                        return;
                    }

                    if ($portPart !== null) {
                        $port = (int) $portPart;
                        if ($port < 1 || $port > 65535) {
                            $fail('Port must be between 1 and 65535.');
                        }
                    }
                },
            ],
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
        ]);

        $organizationName = trim((string) $validated['organization_name']);
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
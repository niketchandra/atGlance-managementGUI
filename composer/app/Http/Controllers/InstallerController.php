<?php

namespace App\Http\Controllers;

use App\Models\AdminSetting;
use App\Models\Organization;
use App\Models\User;
use App\Rules\IpAddressWithOptionalPort;
use App\Support\EnvFile;
use App\Support\InstallationState;
use App\Support\License;
use App\Support\Licensing\Entitlements;
use App\Support\Licensing\LicenseException;
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
            'showInstallId' => (bool) config('licensing.show_install_id'),
            'installId' => License::instanceId(),
        ]);
    }

    /**
     * Installer licence step: checks the uploaded .atgl file (with the 3-hour rule) and
     * returns its details for confirmation. Nothing is saved; install() checks it again.
     */
    public function previewLicense(Request $request): JsonResponse
    {
        if (InstallationState::isInstalled()) {
            return response()->json(['ok' => false, 'message' => 'Application is already installed.'], 403);
        }

        $bytes = self::uploadedLicense($request);
        if ($bytes === null) {
            return response()->json(['ok' => false, 'message' => 'Choose an .atgl licence file (at most 64 KB).'], 422);
        }

        try {
            $license = License::preview($bytes);
        } catch (LicenseException $e) {
            return response()->json(['ok' => false, 'code' => $e->errorCode, 'message' => $e->display()], 422);
        }

        return response()->json([
            'ok' => true,
            'license' => [
                'customer' => $license->customer,
                'plan' => ucfirst($license->plan),
                'nodes' => $license->maxNodes === 0 ? 'Unlimited' : (string) $license->maxNodes,
                'users' => $license->maxUsers === 0 ? 'Unlimited' : (string) $license->maxUsers,
                'policy' => $license->policyType === 'custom' ? 'Custom' : 'Standard',
                'features' => $license->features === [] ? 'None' : implode(', ', array_map([Entitlements::class, 'name'], $license->features)),
                'issued_at' => $license->issuedAt->format('M j, Y H:i') . ' UTC',
                'expires_at' => $license->expiresAt->format('M j, Y'),
            ],
        ]);
    }

    /** The uploaded licence file's bytes, or null when missing, too large or not .atgl. */
    public static function uploadedLicense(Request $request): ?string
    {
        $file = $request->file('license_file');
        if (!$file || !$file->isValid() || $file->getSize() > 65536
            || strtolower((string) $file->getClientOriginalExtension()) !== 'atgl') {
            return null;
        }

        $bytes = @file_get_contents($file->getRealPath());

        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    public function install(Request $request): RedirectResponse
    {
        if (InstallationState::isInstalled()) {
            return redirect()->route('home');
        }

        $validated = $request->validate([
            'organization_name' => ['required', 'string', 'max:255'],
            'app_ip' => ['required', 'string', 'max:255', new IpAddressWithOptionalPort()],
            'superadmin_email' => ['required', 'email', 'max:255'],
            'superadmin_password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $organizationName = trim((string) $validated['organization_name']);

        // The licence file is required and checked before anything is changed. It is
        // saved (activated) only once the database is ready.
        $licenseBytes = self::uploadedLicense($request);
        if ($licenseBytes === null) {
            return back()
                ->withInput($request->except(['superadmin_password', 'superadmin_password_confirmation']))
                ->withErrors(['license_file' => 'Upload your .atgl licence file from atglance.live.']);
        }
        try {
            $license = License::preview($licenseBytes);
        } catch (LicenseException $e) {
            return back()
                ->withInput($request->except(['superadmin_password', 'superadmin_password_confirmation']))
                ->withErrors(['license_file' => $e->display()]);
        }

        $appIpAddress = strtolower(trim((string) $validated['app_ip']));
        $superAdminEmail = strtolower(trim((string) $validated['superadmin_email']));
        $superAdminPassword = (string) $validated['superadmin_password'];
        // A custom domain and HTTPS are set up after installation (Admin Settings > Plugins).
        $normalizedUrl = 'http://' . $appIpAddress;

        EnvFile::set(['APP_URL' => $normalizedUrl]);

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
                AdminSetting::putValue('site', 'site_domain_alias_ip', $appIpAddress);

            }

            License::activate($licenseBytes);

            Artisan::call('optimize:clear');
        } catch (LicenseException $exception) {
            return back()
                ->withInput($request->except(['superadmin_password', 'superadmin_password_confirmation']))
                ->withErrors(['license_file' => $exception->display()]);
        } catch (\Throwable $exception) {
            return back()
                ->withInput()
                ->withErrors(['install' => 'Installation failed: ' . $exception->getMessage()]);
        }

        InstallationState::markInstalled([
            'installed_at' => now()->toDateTimeString(),
            'organization_name' => $organizationName,
            'app_domain' => $appIpAddress,
            'app_ip' => $appIpAddress,
            'app_url' => $normalizedUrl,
            'superadmin_email' => $superAdminEmail,
            'default_superadmin_email' => 'superadmin@admin.com',
            'superadmin_password' => $superAdminPassword,
            'license_active' => true,
            'license_name' => $license->customer,
            'license_plan' => $license->plan,
        ]);

        return redirect()->route('install.info');
    }

    public function info(): View|RedirectResponse
    {
        if (!InstallationState::isInstalled()) {
            return redirect()->route('install.show');
        }

        // The page shows the generated credentials; it closes after the first login.
        if (!InstallationState::isInfoAvailable()) {
            return redirect()->route('home');
        }

        return view('install.info', [
            'installation' => InstallationState::getData(),
        ]);
    }
}
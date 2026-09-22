<?php

namespace App\Http\Controllers;

use App\Support\InstallationState;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;

class InstallerController extends Controller
{
    public function show(): View|RedirectResponse
    {
        if (InstallationState::isInstalled()) {
            return redirect()->route('home');
        }

        return view('install.index', [
            'defaultDomain' => request()->getHttpHost(),
        ]);
    }

    public function install(Request $request): RedirectResponse
    {
        if (InstallationState::isInstalled()) {
            return redirect()->route('home');
        }

        $validated = $request->validate([
            'organization_name' => ['required', 'string', 'max:255'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255'],
            'admin_password' => ['required', 'string', 'min:8', 'confirmed'],
            'app_url' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $input = trim((string) $value);

                    if (preg_match('/^https?:\/\//i', $input)) {
                        $fail('Enter domain or IP only, without http:// or https://.');
                    } elseif (str_contains($input, '/')) {
                        $fail('Domain or IP cannot include path segments.');
                    } elseif (!preg_match('/^[A-Za-z0-9.-]+(?::\d{1,5})?$/', $input)) {
                        $fail('Enter a valid domain or IP address.');
                    }
                },
            ],
            'use_https' => ['required', 'in:0,1'],
        ]);

        $organizationName = trim((string) $validated['organization_name']);
        $appDomain = strtolower(trim((string) $validated['app_url']));
        $httpsEnabled = $validated['use_https'] === '1';
        $normalizedUrl = ($httpsEnabled ? 'https://' : 'http://') . $appDomain;

        try {
            Artisan::call('migrate', ['--force' => true]);

            if (User::where('email', strtolower(trim((string) $validated['admin_email'])))->exists()) {
                return back()
                    ->withInput()
                    ->withErrors(['admin_email' => 'That administrator email is already in use.']);
            }

            config([
                'installer.organization_name' => $organizationName,
                'installer.admin_name' => trim((string) $validated['admin_name']),
                'installer.admin_email' => strtolower(trim((string) $validated['admin_email'])),
                'installer.admin_password' => $validated['admin_password'],
            ]);

            Artisan::call('db:seed', [
                '--class' => 'Database\\Seeders\\InstallationSeeder',
                '--force' => true,
            ]);

            Artisan::call('db:seed', [
                '--class' => 'Database\\Seeders\\AdminUserSeeder',
                '--force' => true,
            ]);

            Artisan::call('optimize:clear');
        } catch (\Throwable $exception) {
            return back()
                ->withInput()
                ->withErrors(['install' => 'Installation failed: ' . $exception->getMessage()]);
        }

        InstallationState::markInstalled([
            'installed_at' => now()->toDateTimeString(),
            'organization_name' => $organizationName,
            'app_domain' => $appDomain,
            'app_url' => $normalizedUrl,
            'https_enabled' => $httpsEnabled,
            'admin_email' => strtolower(trim((string) $validated['admin_email'])),
        ]);

        return redirect()->route('install.info');
    }

    public function info(): View|RedirectResponse
    {
        if (!InstallationState::isInstalled()) {
            return redirect()->route('install.show');
        }

        return view('install.info', ['installation' => InstallationState::getData()]);
    }

}
<?php

namespace App\Providers;

use App\Models\AdminSetting;
use App\Models\Workspace;
use App\Support\SiteProfile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \App\Support\DomainSettings::applyRuntimeAppUrl();

        // The first successful login (password or SSO) closes /install/info.
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Auth\Events\Login::class,
            fn () => \App\Support\InstallationState::markFirstLogin()
        );

        $defaultLogoUrl = asset('branding/atglance-logo.png');
        $defaultFaviconUrl = asset('branding/favicon.ico');

        $sharedSettings = [
            'siteLogoUrl' => $defaultLogoUrl,
            'siteFaviconUrl' => $defaultFaviconUrl,
            'siteContent' => '',
            'siteFeatures' => [],
            'ssoEnabled' => false,
            'ssoProvidersForAuth' => [],
            'disableEmailRegistration' => false,
            'appVersion' => $this->resolveVersionFromDotEnv(),
        ];

        try {
            if (Schema::hasTable('admin_settings')) {
                $overrideLogoUrl = trim((string) AdminSetting::getValue('site_logo_url', ''));
                $storedLogoPath = trim((string) AdminSetting::getValue('site_logo_path', ''));

                if ($overrideLogoUrl !== '') {
                    $sharedSettings['siteLogoUrl'] = $overrideLogoUrl;
                } elseif ($storedLogoPath !== '') {
                    $localStorageBaseUrl = trim((string) env('LOCAL_STORAGE_BASE_URL', ''));
                    if ($localStorageBaseUrl === '') {
                        $siteUrl = rtrim((string) config('app.url', ''), '/');
                        $localStorageBaseUrl = $siteUrl !== '' ? $siteUrl . '/storage' : '';
                    }

                    $s3StorageBaseUrl = trim((string) env('S3_STORAGE_BASE_URL', ''));
                    if ($s3StorageBaseUrl === '') {
                        $bucket = trim((string) config('filesystems.disks.s3.bucket', env('AWS_BUCKET', '')));
                        $region = trim((string) config('filesystems.disks.s3.region', env('AWS_DEFAULT_REGION', '')));
                        if ($bucket !== '' && $region !== '') {
                            $s3StorageBaseUrl = sprintf('https://%s.s3.%s.amazonaws.com/', $bucket, $region);
                        }
                    }

                    $s3Enabled = filter_var($this->getEnvValue('S3_ENABLED', 'false'), FILTER_VALIDATE_BOOL);
                    $baseUrl = $s3Enabled ? $s3StorageBaseUrl : $localStorageBaseUrl;

                    if ($s3Enabled) {
                        $logoPath = ltrim($storedLogoPath, '/');
                        $encodedLogoPath = str_replace('%2F', '/', rawurlencode($logoPath));
                        $sharedSettings['siteLogoUrl'] = url('/site-logo/' . $encodedLogoPath);
                        $baseUrl = '';
                    }

                    if ($baseUrl !== '') {
                        $sharedSettings['siteLogoUrl'] = rtrim($baseUrl, '/') . '/' . ltrim($storedLogoPath, '/');
                    }
                }
                $faviconOverrideUrl = trim((string) AdminSetting::getValue('site_favicon_url', ''));
                if ($faviconOverrideUrl !== '') {
                    $sharedSettings['siteFaviconUrl'] = $faviconOverrideUrl;
                } elseif ($sharedSettings['siteLogoUrl'] !== $defaultLogoUrl) {
                    // An uploaded organisation logo doubles as the favicon; the
                    // default wordmark is too wide, so keep the AtGlance icon.
                    $sharedSettings['siteFaviconUrl'] = (string) $sharedSettings['siteLogoUrl'];
                }

                $sharedSettings['siteContent'] = (string) AdminSetting::getValue('site_content', '');

                $featuresRaw = AdminSetting::getValue('site_features', '[]');
                $decodedFeatures = json_decode((string) $featuresRaw, true);
                $sharedSettings['siteFeatures'] = is_array($decodedFeatures) ? $decodedFeatures : [];

                $disableEmailRegistration = filter_var((string) AdminSetting::getValue('disable_email_registration', 'false'), FILTER_VALIDATE_BOOL);
                $ssoEnabledFlag = \App\Support\SsoSettings::enabled();

                $sharedSettings['ssoEnabled'] = $ssoEnabledFlag;
                $sharedSettings['disableEmailRegistration'] = $disableEmailRegistration;
                // Only providers that are fully set up get a sign-in button.
                $sharedSettings['ssoProvidersForAuth'] = collect($ssoEnabledFlag ? \App\Support\SsoSettings::enabledProviders() : [])
                    ->filter(fn (string $providerKey) => \App\Support\SsoProviders::missingFields($providerKey) === [])
                    ->map(fn (string $providerKey) => [
                        'key' => $providerKey,
                        'label' => \App\Support\SsoProviders::label($providerKey),
                        'icon' => \App\Support\SsoProviders::definition($providerKey)['icon'] ?? 'fas fa-shield-alt',
                    ])
                    ->values()
                    ->all();
            }
        } catch (\Throwable $e) {
            $sharedSettings = [
                'siteLogoUrl' => $defaultLogoUrl,
                'siteFaviconUrl' => $defaultFaviconUrl,
                'siteContent' => '',
                'siteFeatures' => [],
                'ssoEnabled' => false,
                'ssoProvidersForAuth' => [],
                'disableEmailRegistration' => false,
                'appVersion' => $this->resolveVersionFromDotEnv(),
            ];
        }

        View::share($sharedSettings);

        // White-label profile, read per request so a change shows on the next page.
        View::composer('*', function ($view) use ($defaultLogoUrl) {
            $profile = SiteProfile::current();
            $logoUrl = $profile->logoUrl();

            $view->with('brandName', $profile->name());
            $view->with('siteDescription', $profile->description());
            $view->with('publicPages', $profile->availablePages());
            $view->with('siteLogoUrl', $logoUrl !== '' ? $logoUrl : $defaultLogoUrl);
            if ($logoUrl !== '') {
                $view->with('siteFaviconUrl', $logoUrl);
            }
        });

        View::composer('*', function ($view) {
            $workspaceSelectorWorkspaces = collect();
            $workspaceSelectorOptions = collect();
            $selectedWorkspaceId = null;

            try {
                if (
                    Auth::check()
                    && Schema::hasTable('workspaces')
                    && Schema::hasTable('workspace_user')
                ) {
                    $user = Auth::user();

                    if ((int) ($user->rbac_id ?? 0) === 100) {
                        $workspaceSelectorWorkspaces = Workspace::query()
                            ->where('org_id', (int) ($user->org_id ?? 200))
                            ->where('status', 'active')
                            ->orderBy('name')
                            ->get(['id', 'name']);
                    } else {
                        $workspaceSelectorWorkspaces = $user->workspaces()
                            ->orderBy('workspaces.name')
                            ->get(['workspaces.id', 'workspaces.name']);
                    }

                    $workspaceSelectorOptions = $workspaceSelectorWorkspaces->values();

                    $hasUnassignedSystems = false;
                    if (Schema::hasTable('system_register')) {
                        $hasUnassignedSystems = DB::table('system_register')
                            ->where('user_id', (int) $user->id)
                            ->where(function ($query) {
                                $query->where('workspace_id', 0)
                                    ->orWhereNull('workspace_id');
                            })
                            ->exists();
                    }

                    if ($hasUnassignedSystems) {
                        $workspaceSelectorOptions = $workspaceSelectorOptions->concat([
                            (object) [
                                'id' => 0,
                                'name' => 'Unassigned',
                            ],
                        ]);
                    }

                    // Super admin and admins get "All" (id null) as default: consolidated data
                    // across every workspace they can see.
                    $isSuperAdmin = in_array((int) ($user->rbac_id ?? 0), [100, 101], true);

                    if ($isSuperAdmin) {
                        $workspaceSelectorOptions = collect([(object) ['id' => null, 'name' => 'All']])
                            ->concat($workspaceSelectorOptions);
                    }

                    $allowedWorkspaceIds = $workspaceSelectorOptions
                        ->pluck('id')
                        ->filter(fn ($id) => $id !== null)
                        ->map(fn ($id) => (int) $id)
                        ->all();

                    $selectedWorkspaceId = session()->has('selected_workspace_id')
                        ? (int) session('selected_workspace_id')
                        : null;

                    if ($isSuperAdmin) {
                        if ($selectedWorkspaceId !== null && !in_array($selectedWorkspaceId, $allowedWorkspaceIds, true)) {
                            $selectedWorkspaceId = null;
                            session()->forget('selected_workspace_id');
                        }
                    } elseif (!empty($allowedWorkspaceIds)) {
                        if (!in_array($selectedWorkspaceId, $allowedWorkspaceIds, true)) {
                            $preferredWorkspaceIds = array_values(array_filter(
                                $allowedWorkspaceIds,
                                fn (int $workspaceId): bool => $workspaceId !== 0
                            ));

                            $selectedWorkspaceId = $preferredWorkspaceIds[0] ?? $allowedWorkspaceIds[0];
                            session(['selected_workspace_id' => $selectedWorkspaceId]);
                        }
                    } else {
                        $selectedWorkspaceId = null;
                        session()->forget('selected_workspace_id');
                    }
                }
            } catch (\Throwable $e) {
                $workspaceSelectorWorkspaces = collect();
                $workspaceSelectorOptions = collect();
                $selectedWorkspaceId = null;
            }

            $view->with('workspaceSelectorWorkspaces', $workspaceSelectorWorkspaces);
            $view->with('workspaceSelectorOptions', $workspaceSelectorOptions);
            $view->with('selectedWorkspaceId', $selectedWorkspaceId);
        });
    }

    private function getEnvValue(string $key, string $default = ''): string
    {
        $fileValue = $this->readEnvFileValue($key);
        if ($fileValue !== null) {
            return $fileValue;
        }

        $value = env($key);
        if ($value !== null && $value !== false) {
            return trim((string) $value);
        }

        $runtime = getenv($key);
        if ($runtime !== false) {
            return trim((string) $runtime);
        }

        return trim((string) $default);
    }

    private function readEnvFileValue(string $key): ?string
    {
        $envPath = base_path('.env');
        if (!is_readable($envPath)) {
            return null;
        }

        $pattern = '/^' . preg_quote($key, '/') . '=(.*)$/m';
        $contents = @file_get_contents($envPath);
        if ($contents === false || preg_match($pattern, $contents, $matches) !== 1) {
            return null;
        }

        $raw = trim((string) ($matches[1] ?? ''));
        if (
            strlen($raw) >= 2
            && str_starts_with($raw, '"')
            && str_ends_with($raw, '"')
        ) {
            $raw = substr($raw, 1, -1);
            $raw = str_replace('\\"', '"', $raw);
        }

        return trim($raw);
    }

    private function resolveVersionFromDotEnv(): string
    {
        $envPath = base_path('.env');
        if (is_readable($envPath)) {
            $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    $line = trim((string) $line);
                    if (str_starts_with($line, 'VERSION=')) {
                        return trim(substr($line, 8), " \t\n\r\0\x0B\"'");
                    }
                }
            }
        }

        return $this->getEnvValue('VERSION', (string) config('app.version', '0.1.0'));
    }
}

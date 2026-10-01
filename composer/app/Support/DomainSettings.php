<?php

namespace App\Support;

use App\Models\AdminSetting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Custom domain and HTTPS (Admin Settings > Plugins and > Site).
 * See docs/custom-domain.md.
 */
final class DomainSettings
{
    public const FALLBACK_HOST = 'atglance.internal';

    public const MODE_OFF = 'off';
    public const MODE_BUILTIN = 'builtin';
    public const MODE_CUSTOM = 'custom';
    public const MODE_PLATFORM = 'platform';
    public const MODES = [self::MODE_OFF, self::MODE_BUILTIN, self::MODE_CUSTOM, self::MODE_PLATFORM];

    public const PROXY_BUILTIN = 'builtin';
    public const PROXY_PLATFORM = 'platform';
    public const PROXY_UNTRUSTED = 'untrusted';

    private const HOSTNAME_PATTERN = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    public static function pluginEnabled(): bool
    {
        return self::bool('domain_plugin_enabled');
    }

    public static function setPluginEnabled(bool $enabled): void
    {
        AdminSetting::putValue('domain', 'domain_plugin_enabled', $enabled ? 'true' : 'false');
        self::applyAppUrl();
    }

    public static function domain(): string
    {
        return self::normalizeDomain((string) self::get('site_domain_alias', ''));
    }

    public static function httpsMode(): string
    {
        $mode = (string) self::get('site_https_mode', '');
        if (in_array($mode, self::MODES, true)) {
            return $mode;
        }

        return self::bool('site_https_enabled') ? self::MODE_BUILTIN : self::MODE_OFF;
    }

    public static function httpsOn(): bool
    {
        return self::httpsMode() !== self::MODE_OFF;
    }

    public static function serverAddress(): string
    {
        return trim((string) self::get('site_domain_alias_ip', ''));
    }

    public static function serverIp(): string
    {
        return self::stripPort(self::serverAddress());
    }

    public static function stripPort(string $address): string
    {
        $address = trim($address);
        if ($address === '' || filter_var($address, FILTER_VALIDATE_IP)) {
            return $address;
        }
        if (preg_match('/^\[?([^\]]+?)\]?:\d{1,5}$/', $address, $match) && filter_var($match[1], FILTER_VALIDATE_IP)) {
            return $match[1];
        }

        return trim($address, '[]');
    }

    /** Server IP field pre-fill: stored value, then the request host or APP_URL host when it is an IP. */
    public static function suggestedServerAddress(?string $requestHost): string
    {
        if (self::serverAddress() !== '') {
            return self::serverAddress();
        }
        foreach ([(string) $requestHost, (string) parse_url((string) config('app.url'), PHP_URL_HOST)] as $host) {
            $host = trim($host, '[]');
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                return $host;
            }
        }

        return '';
    }

    public static function normalizeDomain(string $domain): string
    {
        return rtrim(strtolower(trim($domain)), '.');
    }

    public static function validateDomain(string $domain): ?string
    {
        if ($domain === '') {
            return null;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $domain)) {
            return 'Enter the domain only, without http:// or https://.';
        }
        if (str_contains($domain, '/')) {
            return 'The domain cannot include a path.';
        }
        if (filter_var(trim($domain, '[]'), FILTER_VALIDATE_IP)) {
            return 'Enter a domain name, not an IP address. The IP address always works on its own.';
        }
        if (str_contains($domain, ':')) {
            return 'The domain cannot include a port.';
        }
        if (!preg_match(self::HOSTNAME_PATTERN, $domain)) {
            return 'Enter a valid domain, for example atglance.internal or atglance.example.com.';
        }

        return null;
    }

    /** Hosts that are never redirected: IPs, localhost, and atglance.internal unless it is the configured domain. */
    public static function isFallbackHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }

        return $host === self::FALLBACK_HOST && self::domain() !== self::FALLBACK_HOST;
    }

    public static function accessUrl(): string
    {
        $domain = self::domain();

        return $domain === '' ? '' : (self::httpsOn() ? 'https://' : 'http://') . $domain;
    }

    public static function appUrl(): string
    {
        if (self::pluginEnabled() && self::domain() !== '') {
            return self::accessUrl();
        }
        $address = self::serverAddress();

        return $address !== '' ? 'http://' . $address : (string) config('app.url');
    }

    public static function applyAppUrl(): void
    {
        $url = self::appUrl();
        EnvFile::set(['APP_URL' => $url]);
        config(['app.url' => $url]);

        // The queue worker keeps its booted config; restart it so queued mail uses the new URL.
        try {
            Artisan::call('queue:restart');
        } catch (\Throwable) {
            // No cache store yet (fresh install): the worker starts with the new URL anyway.
        }
    }

    /**
     * Sets config('app.url') from the database at boot. artisan serve
     * (--no-reload) and the queue worker keep the APP_URL environment they
     * started with, so .env alone does not reach running processes.
     */
    public static function applyRuntimeAppUrl(): void
    {
        try {
            if (InstallationState::isInstalled()) {
                config(['app.url' => self::appUrl()]);
            }
        } catch (\Throwable) {
            // Database not reachable yet: keep APP_URL from the environment.
        }
    }

    public static function save(string $domain, string $mode, string $serverAddress): void
    {
        // Caddy serves a certificate it already holds before asking the app, so
        // a new domain or HTTPS mode needs the built-in proxy restarted.
        if ($domain !== self::domain() || $mode !== self::httpsMode()) {
            self::requestProxyReload();
        }

        AdminSetting::putValue('site', 'site_domain_alias', $domain);
        AdminSetting::putValue('site', 'site_domain_alias_ip', $serverAddress);
        AdminSetting::putValue('domain', 'site_https_mode', $mode);
        AdminSetting::putValue('site', 'site_https_enabled', $mode !== self::MODE_OFF ? 'true' : 'false');
        self::applyAppUrl();
    }

    /** Records that a request came through a proxy; writes at most once a minute per type and scheme. */
    public static function recordProxy(string $type, string $scheme, string $address = ''): void
    {
        if (!Cache::add('domain.proxy_seen.' . $type . '.' . $scheme . '.' . $address, true, 60)) {
            return;
        }
        $seen = self::proxySeen();
        $seen[$type] = ['scheme' => $scheme, 'address' => $address, 'at' => now()->toIso8601String()];
        AdminSetting::putValue('domain', 'proxy_seen', $seen);
    }

    /** @return array<string, array{scheme: string, address: string, at: string}> */
    public static function proxySeen(): array
    {
        $seen = json_decode((string) self::get('proxy_seen', '[]'), true);

        return is_array($seen) ? $seen : [];
    }

    public static function builtinProxySeen(): bool
    {
        return isset(self::proxySeen()[self::PROXY_BUILTIN]);
    }

    /** Touches the marker the entrypoint watches; the built-in proxy restarts within ~5 s. */
    public static function requestProxyReload(): void
    {
        $marker = storage_path('caddy/reload');
        if (!is_dir(dirname($marker))) {
            @mkdir(dirname($marker), 0775, true);
        }
        @touch($marker);
    }

    /** Root certificate of Caddy's internal CA (Caddy storage is /app/storage/caddy). */
    public static function caPath(): string
    {
        return storage_path('caddy/pki/authorities/local/root.crt');
    }

    public static function viewData(?string $requestHost): array
    {
        $domain = self::domain();
        $address = self::suggestedServerAddress($requestHost);
        $ip = self::stripPort($address);
        $certificate = CustomCertificate::current();

        return [
            'plugin_enabled' => self::pluginEnabled(),
            'domain' => $domain,
            'https_mode' => self::httpsMode(),
            'server_address' => $address,
            'server_ip' => $ip,
            'access_url' => self::accessUrl(),
            'proxy_seen' => self::proxySeen(),
            'builtin_seen' => self::builtinProxySeen(),
            'dns_record' => $domain !== '' && $ip !== '' ? $domain . '  A  ' . $ip : '',
            'hosts_line' => $ip !== '' ? $ip . '  ' . ($domain !== '' ? $domain : self::FALLBACK_HOST) : '',
            'local_warning' => str_ends_with($domain, '.local'),
            'certificate' => $certificate === null ? null : CustomCertificate::summary($certificate),
            'ca_available' => is_file(self::caPath()),
        ];
    }

    private static function get(string $key, mixed $default = null): mixed
    {
        try {
            return AdminSetting::getValue($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    private static function bool(string $key): bool
    {
        return filter_var((string) self::get($key, 'false'), FILTER_VALIDATE_BOOL);
    }
}

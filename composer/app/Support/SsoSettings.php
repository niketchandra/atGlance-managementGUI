<?php

namespace App\Support;

use App\Models\AdminSetting;

/** SSO Login plugin state: stored settings first, then SSO_ENABLED / SSO_ENABLED_PROVIDERS in .env (older installs). */
class SsoSettings
{
    public static function enabled(): bool
    {
        $stored = AdminSetting::getValue('sso_enabled', null);
        $source = $stored !== null ? (string) $stored : (self::envValue('SSO_ENABLED') ?? 'false');

        return filter_var($source, FILTER_VALIDATE_BOOL);
    }

    /** @return array<int, string> provider keys selected on the SSO tab (old keys mapped to current ones) */
    public static function enabledProviders(): array
    {
        $stored = AdminSetting::getValue('sso_enabled_providers', null);
        if ($stored === null) {
            $providers = explode(',', (string) self::envValue('SSO_ENABLED_PROVIDERS'));
        } elseif (is_array($stored)) {
            $providers = $stored;
        } else {
            $decoded = json_decode((string) $stored, true);
            $providers = is_array($decoded) ? $decoded : explode(',', (string) $stored);
        }

        return collect($providers)
            ->map(fn ($provider) => SsoProviders::normalizeKey((string) $provider))
            ->filter(fn (string $provider) => SsoProviders::exists($provider))
            ->unique()
            ->values()
            ->all();
    }

    private static function envValue(string $key): ?string
    {
        $path = base_path('.env');
        $contents = is_readable($path) ? @file_get_contents($path) : false;
        if ($contents !== false && preg_match('/^' . preg_quote($key, '/') . '=(.*)$/m', $contents, $match) === 1) {
            return trim(trim($match[1]), '"');
        }
        $value = env($key);

        return $value === null ? null : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
    }
}

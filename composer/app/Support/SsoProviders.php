<?php

namespace App\Support;

use App\Models\AdminSetting;

/**
 * SSO provider catalog (config/sso.php) plus each provider's saved settings.
 *
 * Settings: admin_settings 'sso_provider_config' (JSON, no secrets) and
 * 'sso_provider_client_secrets' (JSON, encrypted). Older installs kept client IDs,
 * tenant IDs and URLs in separate settings; those are read as fallbacks.
 */
class SsoProviders
{
    public const RESERVED_MICROSOFT_TENANTS = ['common', 'organizations', 'consumers'];

    /** @return array<string, array> */
    public static function all(): array
    {
        return config('sso.providers', []);
    }

    public static function exists(string $provider): bool
    {
        return isset(self::all()[$provider]);
    }

    public static function definition(string $provider): array
    {
        return self::all()[$provider] ?? [];
    }

    public static function normalizeKey(string $provider): string
    {
        $provider = strtolower(trim($provider));

        return config('sso.aliases.' . $provider, $provider);
    }

    public static function label(string $provider): string
    {
        if ($provider === 'oidc') {
            $custom = trim((string) self::value('oidc', 'button_label'));
            if ($custom !== '') {
                return $custom;
            }
        }

        return (string) (self::definition($provider)['label'] ?? ucfirst($provider));
    }

    public static function callbackUrl(string $provider): string
    {
        return route('auth.sso.callback', ['provider' => $provider]);
    }

    /** All saved non-secret settings: [provider => [field => value]]. */
    public static function savedConfig(): array
    {
        return self::decode(AdminSetting::getValue('sso_provider_config', '{}'));
    }

    public static function value(string $provider, string $field): string
    {
        $saved = self::savedConfig()[$provider][$field] ?? null;
        if ($saved !== null) {
            return trim((string) $saved);
        }

        return self::legacyValue($provider, $field)
            ?? (string) (self::definition($provider)['fields'][$field]['default'] ?? '');
    }

    public static function flag(string $provider, string $field): bool
    {
        return filter_var(self::value($provider, $field), FILTER_VALIDATE_BOOL);
    }

    public static function clientId(string $provider): string
    {
        return self::value($provider, 'client_id');
    }

    public static function clientSecret(string $provider): string
    {
        $secrets = self::secrets();

        return trim((string) ($secrets[$provider] ?? $secrets[array_search($provider, config('sso.aliases', []), true) ?: ''] ?? ''));
    }

    public static function secrets(): array
    {
        return self::decode(AdminSetting::getValue('sso_provider_client_secrets', '{}'));
    }

    /** Where the provider's OpenID Connect discovery document lives; '' if not configured. */
    public static function issuer(string $provider): string
    {
        return match ($provider) {
            'google' => 'https://accounts.google.com',
            'microsoft' => self::value('microsoft', 'tenant_id') === '' ? ''
                : 'https://login.microsoftonline.com/' . rawurlencode(self::value('microsoft', 'tenant_id')) . '/v2.0',
            'gitlab' => rtrim(self::value('gitlab', 'base_url'), '/'),
            'okta' => self::value('okta', 'domain') === '' ? ''
                : rtrim(self::value('okta', 'domain'), '/') . (self::value('okta', 'auth_server') !== '' ? '/oauth2/' . rawurlencode(self::value('okta', 'auth_server')) : ''),
            'auth0' => self::value('auth0', 'domain') === '' ? ''
                : 'https://' . trim(preg_replace('#^https?://#i', '', self::value('auth0', 'domain')), '/') . '/',
            'authentik' => self::value('authentik', 'issuer'),
            'oidc' => self::value('oidc', 'issuer'),
            default => '',
        };
    }

    /** Fields the provider needs before it can be enabled, as human-readable labels. */
    public static function missingFields(string $provider): array
    {
        $definition = self::definition($provider);
        $missing = [];
        if (self::clientId($provider) === '') {
            $missing[] = $definition['client_id_label'] ?? 'Client ID';
        }
        if (self::clientSecret($provider) === '') {
            $missing[] = $definition['client_secret_label'] ?? 'Client secret';
        }
        foreach ($definition['fields'] ?? [] as $field => $meta) {
            if (($meta['required'] ?? false) && self::value($provider, $field) === '') {
                $missing[] = $meta['label'];
            }
        }

        return $missing;
    }

    private static function legacyValue(string $provider, string $field): ?string
    {
        $legacyKeys = array_merge([$provider], array_keys(array_filter(config('sso.aliases', []), fn ($target) => $target === $provider)));
        $pick = function (string $setting) use ($legacyKeys): ?string {
            $values = self::decode(AdminSetting::getValue($setting, '{}'));
            foreach ($legacyKeys as $key) {
                if (trim((string) ($values[$key] ?? '')) !== '') {
                    return trim((string) $values[$key]);
                }
            }

            return null;
        };

        return match (true) {
            $field === 'client_id' => $pick('sso_provider_client_ids'),
            $field === 'tenant_id' && $provider === 'microsoft' => $pick('sso_provider_tenant_ids'),
            $field === 'enterprise_url' && $provider === 'github' => self::legacyGithubEnterpriseUrl($pick('sso_provider_urls')),
            default => null,
        };
    }

    private static function legacyGithubEnterpriseUrl(?string $url): ?string
    {
        $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));

        return $host === '' || $host === 'github.com' ? null : 'https://' . $host;
    }

    private static function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }
}

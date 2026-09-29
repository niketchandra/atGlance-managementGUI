<?php

namespace App\Support;

use App\Models\AdminSetting;
use App\Services\LicenseClient;
use Illuminate\Support\Str;

/**
 * The instance licence, stored in admin_settings (group "license").
 *
 * Without an active licence no user can be created or registered and no API
 * key can be created. The installer may skip the licence ("I'll add later");
 * an admin then adds it from Admin Settings > Licence.
 */
class License
{
    public const REQUIRED_MESSAGE = 'A valid AtGlance licence is required. An admin must add the licence key in Admin Settings > Licence.';

    public static function isActive(): bool
    {
        try {
            return AdminSetting::getValue('license_status') === LicenseClient::VERIFIED_STATUS
                && trim((string) AdminSetting::getValue('license_key', '')) !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Saves a verified licence from a successful LicenseClient::verify() result.
     */
    public static function store(string $key, array $result): void
    {
        $details = $result['details'] ?? [];

        AdminSetting::putValue('license', 'license_key', trim($key), true);
        AdminSetting::putValue('license', 'license_status', (string) ($result['status'] ?? ''));
        AdminSetting::putValue('license', 'license_name', (string) ($details['name'] ?? ''));
        AdminSetting::putValue('license', 'license_plan', (string) ($details['plan'] ?? ''));
        AdminSetting::putValue('license', 'license_expires_at', (string) ($details['expires_at'] ?? ''));
        AdminSetting::putValue('license', 'license_details', $details['extra'] ?? []);
        AdminSetting::putValue('license', 'license_verified_at', now()->toDateTimeString());
    }

    /**
     * @return array{active: bool, name: string, plan: string, expires_at: string, verified_at: string, masked_key: string, details: array<string, mixed>}
     */
    public static function summary(): array
    {
        $key = trim((string) AdminSetting::getValue('license_key', ''));
        $details = json_decode((string) AdminSetting::getValue('license_details', '[]'), true);

        return [
            'active' => self::isActive(),
            'name' => (string) AdminSetting::getValue('license_name', ''),
            'plan' => (string) AdminSetting::getValue('license_plan', ''),
            'expires_at' => (string) AdminSetting::getValue('license_expires_at', ''),
            'verified_at' => (string) AdminSetting::getValue('license_verified_at', ''),
            'masked_key' => self::mask($key),
            'details' => is_array($details) ? $details : [],
        ];
    }

    /**
     * Identity sent with every verify call. atglance.live binds the licence to
     * the first console that verifies it, keyed by instance_id.
     *
     * @return array{instance_id: string, hostname: string, version: string}
     */
    public static function consoleIdentity(): array
    {
        return [
            'instance_id' => self::instanceId(),
            'hostname' => (string) (gethostname() ?: 'atglance-console'),
            'version' => (string) config('app.version', '1.0.0'),
        ];
    }

    /**
     * A UUID generated once per console and kept in storage (not the DB): the
     * installer verifies the licence before the database is migrated, and the
     * container hostname changes whenever the container is recreated.
     */
    public static function instanceId(): string
    {
        $path = storage_path('app/installer/instance_id');
        $existing = is_file($path) ? trim((string) @file_get_contents($path)) : '';
        if ($existing !== '') {
            return $existing;
        }

        $id = (string) Str::uuid();
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0755, true);
        }
        @file_put_contents($path, $id);

        return $id;
    }

    public static function portalUrl(): string
    {
        return (string) config('services.atglance_license.portal_url', 'https://atglance.live');
    }

    private static function mask(string $key): string
    {
        if ($key === '') {
            return '';
        }

        return strlen($key) <= 10
            ? str_repeat('•', strlen($key))
            : substr($key, 0, 6) . str_repeat('•', 8) . substr($key, -4);
    }
}

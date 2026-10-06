<?php

namespace App\Support;

use App\Models\AdminSetting;
use App\Services\LicenseClient;
use Illuminate\Support\Carbon;
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

    /** A licence key is saved (active or not); changing it is then a replacement. */
    public static function hasStoredKey(): bool
    {
        try {
            return trim((string) AdminSetting::getValue('license_key', '')) !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Saves a licence from a successful LicenseClient::activate() result.
     */
    public static function store(string $key, array $result): void
    {
        $details = $result['details'] ?? [];
        $extra = $details['extra'] ?? [];

        AdminSetting::putValue('license', 'license_key', trim($key), true);
        AdminSetting::putValue('license', 'license_status', (string) ($result['status'] ?? ''));
        AdminSetting::putValue('license', 'license_name', (string) ($details['name'] ?? ''));
        AdminSetting::putValue('license', 'license_plan', (string) ($details['plan'] ?? ''));
        AdminSetting::putValue('license', 'license_expires_at', (string) ($details['expires_at'] ?? ''));
        AdminSetting::putValue('license', 'license_details', $extra);
        AdminSetting::putValue('license', 'license_activated_at', (string) ($extra['console.activated_at'] ?? now()->toDateTimeString()));
        AdminSetting::putValue('license', 'license_verified_at', now()->toDateTimeString());
        AdminSetting::putValue('license', 'license_check_message', '');
    }

    /**
     * Daily check (`license:check`): asks atglance.live whether the stored
     * licence is still in use by this console, without changing anything there.
     *
     * The licence stays active while the key is in_use by this console. It is
     * turned off when atglance.live rejects the key (401/403), or reports it
     * "available" (released) or in use by another console. When atglance.live
     * cannot be reached or returns a server error, nothing changes.
     *
     * @return array{result: string, message: string} result: none, active, inactive or skipped
     */
    public static function check(LicenseClient $client): array
    {
        $key = trim((string) AdminSetting::getValue('license_key', ''));
        if ($key === '') {
            return ['result' => 'none', 'message' => 'No licence key is stored.'];
        }

        $response = $client->verify($key);
        $httpStatus = (int) ($response['http_status'] ?? 0);
        $details = $response['details'];
        $extra = $details['extra'] ?? [];
        $owner = trim((string) ($extra['console.instance_id'] ?? ''));

        if ($response['ok'] && $response['status'] === LicenseClient::VERIFIED_STATUS && ($owner === '' || $owner === self::instanceId())) {
            AdminSetting::putValue('license', 'license_status', LicenseClient::VERIFIED_STATUS);
            AdminSetting::putValue('license', 'license_name', (string) ($details['name'] ?? ''));
            AdminSetting::putValue('license', 'license_plan', (string) ($details['plan'] ?? ''));
            AdminSetting::putValue('license', 'license_expires_at', (string) ($details['expires_at'] ?? ''));
            AdminSetting::putValue('license', 'license_details', $extra);
            if (!empty($extra['console.activated_at'])) {
                AdminSetting::putValue('license', 'license_activated_at', (string) $extra['console.activated_at']);
            }
            AdminSetting::putValue('license', 'license_verified_at', now()->toDateTimeString());
            AdminSetting::putValue('license', 'license_check_message', '');

            return ['result' => 'active', 'message' => 'Licence is active.'];
        }

        if (!$response['ok'] && !in_array($httpStatus, [401, 403, 409], true)) {
            return ['result' => 'skipped', 'message' => 'Licence not checked: ' . $response['message']];
        }

        if ($response['ok']) {
            $status = $response['status'] === 'available' ? 'available' : 'in_use_elsewhere';
            $message = $status === 'available'
                ? 'This licence is no longer activated for this console. Activate it again below.'
                : 'This licence is now in use by another AtGlance console.';
        } else {
            $status = $response['status'] !== '' ? $response['status'] : 'rejected';
            $message = $response['message'];
        }

        AdminSetting::putValue('license', 'license_status', $status);
        AdminSetting::putValue('license', 'license_verified_at', now()->toDateTimeString());
        AdminSetting::putValue('license', 'license_check_message', $message);

        return ['result' => 'inactive', 'message' => $message];
    }

    /**
     * @return array{active: bool, name: string, plan: string, expires_at: string, activated_at: string, verified_at: string, check_message: string, masked_key: string, details: array<string, mixed>}
     */
    public static function summary(): array
    {
        $key = trim((string) AdminSetting::getValue('license_key', ''));
        $details = json_decode((string) AdminSetting::getValue('license_details', '[]'), true);
        $details = is_array($details) ? $details : [];
        // Licence owner fields (user.*) are not shown in the console.
        $details = array_filter($details, fn ($field) => !str_starts_with((string) $field, 'user.'), ARRAY_FILTER_USE_KEY);

        return [
            'active' => self::isActive(),
            'name' => (string) AdminSetting::getValue('license_name', ''),
            'plan' => (string) AdminSetting::getValue('license_plan', ''),
            'expires_at' => (string) AdminSetting::getValue('license_expires_at', ''),
            'activated_at' => (string) AdminSetting::getValue('license_activated_at', ''),
            'verified_at' => (string) AdminSetting::getValue('license_verified_at', ''),
            'check_message' => (string) AdminSetting::getValue('license_check_message', ''),
            'masked_key' => self::mask($key),
            'details' => $details,
        ];
    }

    /**
     * A stored date/time as a date only ("Sep 30, 2026"); '' when empty.
     */
    /**
     * Sidebar expiry line: "Expires On <date> (N days left)", "Expired On <date>", or "No expiry date".
     *
     * @return array{text: string, days_left: int|null}
     */
    public static function expiry(string $value): array
    {
        if (trim($value) === '') {
            return ['text' => 'No expiry date', 'days_left' => null];
        }

        try {
            $expires = Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return ['text' => 'Expires On ' . $value, 'days_left' => null];
        }

        $daysLeft = (int) now()->startOfDay()->diffInDays($expires, false);
        if ($daysLeft < 0) {
            return ['text' => 'Expired On ' . $expires->format('M j, Y'), 'days_left' => $daysLeft];
        }

        return [
            'text' => 'Expires On ' . $expires->format('M j, Y') . ' (' . ($daysLeft === 0 ? 'today' : $daysLeft . ' ' . ($daysLeft === 1 ? 'day' : 'days') . ' left') . ')',
            'days_left' => $daysLeft,
        ];
    }

    public static function date(string $value): string
    {
        if (trim($value) === '') {
            return '';
        }

        try {
            return Carbon::parse($value)->format('M j, Y');
        } catch (\Throwable) {
            return $value;
        }
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
            'version' => (string) config('app.version'),
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

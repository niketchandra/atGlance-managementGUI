<?php

namespace App\Support;

use App\Models\AdminSetting;
use Illuminate\Support\Facades\Schema;

/**
 * Service account and key settings, stored in admin_settings (group service_accounts).
 */
final class ServiceAccountSettings
{
    private const GROUP = 'service_accounts';

    private const DEFAULTS = [
        'sa_default_key_days' => 90,
        'sa_max_key_days' => 365,
        'sa_org_quota' => 10,
        'sa_workspace_quota' => 5,
        'sa_expiry_notice_days' => '14,3',
        'sa_require_for_registration' => '0',
    ];

    public static function defaultKeyDays(): int
    {
        return min(self::int('sa_default_key_days', 1, 3650), self::maxKeyDays());
    }

    public static function maxKeyDays(): int
    {
        return self::int('sa_max_key_days', 1, 3650);
    }

    public static function orgQuota(): int
    {
        return self::int('sa_org_quota', 0, 1000);
    }

    public static function workspaceQuota(): int
    {
        return self::int('sa_workspace_quota', 0, 1000);
    }

    /**
     * @return list<int> days before expiry, largest first
     */
    public static function noticeDays(): array
    {
        $days = array_filter(
            array_map(fn ($part) => (int) trim($part), explode(',', (string) self::raw('sa_expiry_notice_days'))),
            fn (int $day) => $day > 0 && $day <= 365,
        );
        $days = array_values(array_unique($days));
        rsort($days);

        return $days;
    }

    public static function requireForRegistration(): bool
    {
        return in_array((string) self::raw('sa_require_for_registration'), ['1', 'true', 'on'], true);
    }

    /**
     * @param array<string, mixed> $values keys from DEFAULTS; others are ignored
     */
    public static function save(array $values): void
    {
        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            if ($key === 'sa_require_for_registration') {
                $value = $value ? '1' : '0';
            }
            if ($key === 'sa_max_key_days') {
                $value = max(1, min(3650, (int) $value));
            }
            AdminSetting::putValue(self::GROUP, $key, (string) $value);
        }
    }

    private static function int(string $key, int $min, int $max): int
    {
        return max($min, min($max, (int) self::raw($key)));
    }

    private static function raw(string $key): mixed
    {
        if (!Schema::hasTable('admin_settings')) {
            return self::DEFAULTS[$key];
        }

        return AdminSetting::getValue($key, self::DEFAULTS[$key]);
    }
}

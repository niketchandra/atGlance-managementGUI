<?php

namespace App\Support;

use App\Models\AdminSetting;
use Cron\CronExpression;

/**
 * Settings for the two scheduled backups on the Backup & Restore tab:
 * the database (SQL dump) and the configuration & console files.
 *
 * Each backup has its own schedule, destinations (local copies on this
 * server and/or S3) and the number of copies kept in each place. Settings
 * saved before the split (backup_config_to_s3, backup_portal_to_s3, ...)
 * are read as defaults until the tab is saved again.
 */
class BackupSettings
{
    public const TYPE_DATABASE = 'database';
    public const TYPE_CONFIG = 'config';

    public const TYPES = [self::TYPE_DATABASE, self::TYPE_CONFIG];

    public const LABELS = [
        self::TYPE_DATABASE => 'Database backup',
        self::TYPE_CONFIG => 'Configuration & console files backup',
    ];

    public const FREQUENCIES = [
        'hourly' => ['label' => 'Every hour', 'expression' => '0 * * * *'],
        'every_six_hours' => ['label' => 'Every 6 hours', 'expression' => '0 */6 * * *'],
        'every_twelve_hours' => ['label' => 'Every 12 hours', 'expression' => '0 */12 * * *'],
        'daily' => ['label' => 'Every day', 'expression' => '0 0 * * *'],
        'weekly' => ['label' => 'Every week', 'expression' => '0 0 * * 0'],
        'monthly' => ['label' => 'Every month', 'expression' => '0 0 1 * *'],
    ];

    public const CUSTOM = 'custom';
    public const DEFAULT_KEEP = 7;
    public const MAX_KEEP = 365;

    /** Settings that existed before each backup had its own section. */
    private const LEGACY = [
        self::TYPE_DATABASE => ['toggle' => 'backup_portal_to_s3', 'cron' => 'backup_portal_cron'],
        self::TYPE_CONFIG => ['toggle' => 'backup_config_to_s3', 'cron' => 'backup_config_cron'],
    ];

    public static function masterEnabled(): bool
    {
        return self::bool('backup_restore_enabled', false);
    }

    /**
     * @return array{enabled: bool, frequency: string, cron_expression: string, to_s3: bool, to_local: bool, keep_s3: int, keep_local: int}
     */
    public static function get(string $type): array
    {
        $legacy = self::LEGACY[$type];
        $legacyOn = self::bool($legacy['toggle'], false);

        $frequency = strtolower(trim((string) (self::string(self::key($type, 'frequency'), null)
            ?? AdminSetting::getValue($legacy['cron'], ''))));
        if ($frequency !== self::CUSTOM && !isset(self::FREQUENCIES[$frequency])) {
            $frequency = '';
        }

        return [
            'enabled' => self::bool(self::key($type, 'enabled'), $legacyOn),
            'frequency' => $frequency,
            'cron_expression' => (string) self::string(self::key($type, 'cron_expression'), ''),
            'to_s3' => self::bool(self::key($type, 'to_s3'), $legacyOn),
            'to_local' => self::bool(self::key($type, 'to_local'), false),
            'keep_s3' => self::keep(self::key($type, 'keep_s3')),
            'keep_local' => self::keep(self::key($type, 'keep_local')),
        ];
    }

    public static function save(string $type, array $values): void
    {
        foreach (['enabled', 'to_s3', 'to_local'] as $flag) {
            AdminSetting::putValue('storage', self::key($type, $flag), !empty($values[$flag]) ? 'true' : 'false');
        }

        AdminSetting::putValue('storage', self::key($type, 'frequency'), (string) ($values['frequency'] ?? ''));
        AdminSetting::putValue('storage', self::key($type, 'cron_expression'), trim((string) ($values['cron_expression'] ?? '')));
        AdminSetting::putValue('storage', self::key($type, 'keep_s3'), (string) self::clampKeep($values['keep_s3'] ?? self::DEFAULT_KEEP));
        AdminSetting::putValue('storage', self::key($type, 'keep_local'), (string) self::clampKeep($values['keep_local'] ?? self::DEFAULT_KEEP));
    }

    /**
     * The cron expression the scheduler uses, or null when none is set.
     */
    public static function expression(array $settings): ?string
    {
        if ($settings['frequency'] === self::CUSTOM) {
            return self::isValidCron($settings['cron_expression']) ? $settings['cron_expression'] : null;
        }

        return self::FREQUENCIES[$settings['frequency']]['expression'] ?? null;
    }

    public static function frequencyLabel(array $settings): string
    {
        if ($settings['frequency'] === self::CUSTOM) {
            return 'Custom (' . $settings['cron_expression'] . ')';
        }

        return self::FREQUENCIES[$settings['frequency']]['label'] ?? '';
    }

    public static function isValidCron(string $expression): bool
    {
        $expression = trim($expression);

        // Five fields only: no @-macros or seconds, so the UI and the scheduler agree.
        return preg_match('/^\S+(\s+\S+){4}$/', $expression) === 1 && CronExpression::isValidExpression($expression);
    }

    public static function lastRunKey(string $type): string
    {
        return 'backup_' . $type . '_last_run';
    }

    private static function key(string $type, string $name): string
    {
        return 'backup_' . $type . '_' . $name;
    }

    private static function keep(string $key): int
    {
        return self::clampKeep(AdminSetting::getValue($key, self::DEFAULT_KEEP));
    }

    private static function clampKeep(mixed $value): int
    {
        $number = (int) $value;

        return $number < 1 ? self::DEFAULT_KEEP : min($number, self::MAX_KEEP);
    }

    private static function bool(string $key, bool $default): bool
    {
        $value = AdminSetting::getValue($key, null);

        return $value === null || $value === '' ? $default : filter_var((string) $value, FILTER_VALIDATE_BOOL);
    }

    private static function string(string $key, ?string $default): ?string
    {
        $value = AdminSetting::getValue($key, null);

        return $value === null ? $default : (string) $value;
    }
}

<?php

namespace App\Support;

use App\Models\AdminSetting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Runtime S3 settings. The admin S3 tab saves to admin_settings and .env, but
 * `php artisan serve` keeps the env it started with, so admin_settings is the
 * source of truth and config/env are only the fallback.
 */
class S3Settings
{
    private const FIELDS = [
        'key' => 's3_access_key',
        'secret' => 's3_secret_key',
        'region' => 's3_region',
        'bucket' => 's3_bucket',
    ];

    public static function enabled(): bool
    {
        $fallback = filter_var(env('S3_ENABLED', false), FILTER_VALIDATE_BOOL) ? 'true' : 'false';

        return filter_var((string) AdminSetting::getValue('s3_enabled', $fallback), FILTER_VALIDATE_BOOL);
    }

    /**
     * @return array{key: string, secret: string, region: string, bucket: string}
     */
    public static function credentials(): array
    {
        $credentials = [];

        foreach (self::FIELDS as $name => $settingKey) {
            $value = self::normalize(AdminSetting::getValue($settingKey, ''));
            if ($value === '') {
                $value = self::normalize(config('filesystems.disks.s3.' . $name, ''));
            }

            $credentials[$name] = self::decrypt($value);
        }

        return $credentials;
    }

    /**
     * Points the s3 disk at the saved credentials. Returns false when any is missing.
     */
    public static function configureDisk(): bool
    {
        $credentials = self::credentials();

        if (in_array('', $credentials, true)) {
            return false;
        }

        $current = array_intersect_key((array) config('filesystems.disks.s3', []), $credentials);

        if ($current != $credentials) {
            foreach ($credentials as $name => $value) {
                Config::set('filesystems.disks.s3.' . $name, $value);
            }

            // Drop any disk instance built with the old credentials.
            Storage::forgetDisk('s3');
        }

        return true;
    }

    /**
     * The disk new uploads go to: s3 when S3 is enabled and configured, else local.
     */
    public static function activeDisk(): string
    {
        return self::enabled() && self::configureDisk() ? 's3' : 'local';
    }

    /**
     * Secrets written to .env by the admin UI carry an `ENC:` prefix.
     */
    public static function decrypt(string $value): string
    {
        if (!str_starts_with($value, 'ENC:')) {
            return $value;
        }

        try {
            return trim(Crypt::decryptString(substr($value, 4)));
        } catch (Throwable $e) {
            return '';
        }
    }

    private static function normalize(mixed $value): string
    {
        $normalized = trim((string) ($value ?? ''));

        return strtolower($normalized) === 'null' ? '' : $normalized;
    }
}

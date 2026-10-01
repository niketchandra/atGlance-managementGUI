<?php

namespace App\Support;

class InstallationState
{
    public static function isInstalled(): bool
    {
        return is_file(self::markerPath());
    }

    public static function getData(): array
    {
        if (!self::isInstalled()) {
            return [];
        }

        $raw = @file_get_contents(self::markerPath());
        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function markInstalled(array $data): void
    {
        $directory = dirname(self::markerPath());
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents(
            self::markerPath(),
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * /install/info shows the generated credentials; it is available only
     * until the first successful login.
     */
    public static function isInfoAvailable(): bool
    {
        return self::isInstalled() && empty(self::getData()['first_login_at']);
    }

    /**
     * Called on every successful login (Login event). The first one closes
     * /install/info and removes the super admin password from the marker.
     */
    public static function markFirstLogin(): void
    {
        if (!self::isInfoAvailable()) {
            return;
        }

        $data = self::getData();
        unset($data['superadmin_password']);
        $data['first_login_at'] = now()->toDateTimeString();
        self::markInstalled($data);
    }

    private static function markerPath(): string
    {
        return storage_path('app/installer/installed.json');
    }
}
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

    private static function markerPath(): string
    {
        return storage_path('app/installer/installed.json');
    }
}